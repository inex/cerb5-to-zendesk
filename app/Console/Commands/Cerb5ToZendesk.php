<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

use Zendesk\API\Client as ZendeskAPI;
use Carbon\Carbon;
use DB;
use Validator;

class Cerb5ToZendesk extends Command {

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'cerb5-to-zendesk';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate Cerb5 tickets to Zendesk';

    protected $zd = null;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // make sure you set your Zendesk auth details in .env
        $this->zd = new ZendeskAPI( env( 'ZENDESK_SUBDOMAIN', 'subdomain' ), env( 'ZENDESK_EMAIL', 'email' ) );
        $this->zd->setAuth('token', env( 'ZENDESK_TOKEN', 'token' ) );

        // This is the main control loop - iterate over all tickets in Cerberus but exclude:
        // - those that have been deleted
        // - those that are SPAM (for us, that was bucket_id = 4)
        //
        // We initially tried ensuring a ticket wasn't in Zendesk already based on the Cerb5 mask
        // but the Zendesk API would not work. Instead, we used a Zendesk sandbox environment to
        // ensure we had a clean run and then performed it on the real Zendesk environment.
        //
        // If you interrupt the process and want to start later, just add 'and id > ?' in the SQL
        // below. This will work as we order by id ascending.

        $tickets = DB::select('select * from ticket where is_deleted = 0 and bucket_id != 4 order by id asc' );

        foreach( $tickets as $ticket )
        {
            // does this ticket exist on Zendesk already?
            // NB: this did not work for us - this never returned a ticket
            //if( $this->zdFindTicketByMask( $ticket->mask ) )
            //    continue;

            try
            {
                if( $zdTicket = $this->zdCreateTicket( $ticket ) )
                    echo $ticket->id . ' ';
            }
            catch( \Exception $e )
            {
                $this->error( "\n{$ticket->mask} failed" );
                $this->error( $e );
                var_dump( $this->zd->getDebug() );
            }
        }
    }

    private function zdCreateTicket( $cerbTicket )
    {
        // Find or create the appropriate organisation in Zendesk
        $zdOrgId       = $this->zdResolveOrg( $cerbTicket->org_id );

        // Find or create the appropriate user in Zendesk
        $zdRequesterId = $this->zdResolveAddress( $cerbTicket->first_wrote_address_id );

        // Find or create the appropriate agent -> static look up, this function needs to be edited
        $zdOwnerId     = $this->zdResolveOwner( $cerbTicket->owner_id );

        // Get all messages and comments from Cerberus for this ticket
        $messages    = $this->cerbGetMessagesForTicket( $cerbTicket );
        $comments    = $this->cerbGetCommentsForTicket( $cerbTicket, $messages );

        // build up the Zendesk ticket:
        $ticket = [
            'requester_id'      => $zdRequesterId,
            'submitter_id'      => $zdRequesterId,
            'assignee_id'       => $zdOwnerId,
            'subject'           => $cerbTicket->subject,
            'external_id'       => $cerbTicket->mask,
            'created_at'        => $this->fixDate( Carbon::createFromTimeStamp( $cerbTicket->created_date )->toIso8601String() ),
            'updated_at'        => $this->fixDate( Carbon::createFromTimeStamp( $cerbTicket->updated_date )->toIso8601String() )
        ];

        // add Cerb5 messages as public comments to the Zendesk ticket:
        $zdComments = []; $i = 0;
        foreach( $messages as $comment )
        {
            // ignore blank messages (throws an exception with Zendesk)
            if( trim( $comment->data ) == '' ) continue;

            // if there are attachments, upload them to Zendesk and get the tokens
            $attachmentTokens = $this->zdUploadAttachments( $comment );

            $zdComments[$i] = [
                'author_id'    => $this->zdResolveAddress( $comment->address_id ),
                'public'       => true,
                'value'        => $comment->data,
                'created_at'   => $this->fixDate( Carbon::createFromTimeStamp( $comment->created )->toIso8601String() )
            ];

            if( is_array( $attachmentTokens ) && count( $attachmentTokens ) )
                $zdComments[$i]['uploads'] = $attachmentTokens;

            $i++;
        }

        // add any private Cerberus comments to Zendesk
        foreach( $comments as $comment )
        {
            if( trim( $comment->comment ) == '' ) continue;

            $zdComments[] = [
                'author_id'    => $this->zdResolveOwner( $comment->address_id ),
                'public'       => false,
                'value'        => $comment->comment,
                'created_at'   => $this->fixDate( Carbon::createFromTimeStamp( $comment->created )->toIso8601String() )
            ];
        }

        $ticket[ 'comments' ] = $zdComments;

        // set the ticket status
        if( $cerbTicket->is_closed )
                $ticket['status'] = 'closed';
        else if( $cerbTicket->is_waiting )
            $ticket['status'] = 'pending';
        else
            $ticket['status'] = 'open';

        // you'll see this usleep() before all Zendesk requests. This is to prevent us hitting their
        // rate limiter. Ideally I'd have handled this proberly but this works just as well.
        // At time of writing, it was 200 reqs / min.
        usleep( 60/200 );

        // and import the ticket:
        return $this->zd->tickets()->import( $ticket );
    }


    /**
     * Upload any attachments associated with a message and return the tokens
     * provided by Zendesk
     */
    private function zdUploadAttachments( $msg )
    {
        $attachments = $this->cerbGetAttachmentsForMessage( $msg );

        if( !is_array( $attachments ) && !count( $attachments ) )
            return false;

        $tokens = [];

        foreach( $attachments as $a )
        {
            // At some point, we upgraded Cerberus but didn't transfer the attachments. We had backups
            // though so we handled it as follows:
            //
            // if( $a->id <= 1350 )
            //     $fn = '/data/www/old-sites/cerb5/storage/attachments/' . $a->storage_key;
            // else
            //     $fn = '/data/www/cerb5.git/storage/attachments/' . $a->storage_key;
            //
            // typically, you should just need:

            $fn = env( 'CERB5_STORAGE_PATH' ) . '/attachments/' . $a->storage_key;

            if( !file_exists( $fn ) ) {
                $this->error( "\nCould not find attachment: $fn" );
                continue;
            }

            usleep( 60/200 );
            $zdAttachment = $this->zd->attachments()->upload( [ 'file' => $fn, 'name' => $a->name ] );

            if( is_object( $zdAttachment ) && $zdAttachment->upload )
                $tokens[] = $zdAttachment->upload->token;
            else
                $this->error( "\nCould not upload $fn" );
        }

        return $tokens;
    }

    /**
     * Get all messages for a given Cerberus ticket
     *
     * Returns as an ordered array indexed by message ID
     */
    private function cerbGetMessagesForTicket( $ticket )
    {
        $dbMsgs = DB::select( 'SELECT m.id as id, m.created_date as created, m.address_id as address_id,
                    m.is_outgoing as is_outgoing, m.worker_id as worker_id,
                    a.email as email, a.first_name as firstname, a.last_name as lastname,
                    a.contact_org_id as orgid, s.data as data
                from message m
                    left join address a on m.address_id = a.id
                    left join storage_message_content s on s.id = m.storage_key
                where m.ticket_id = :ticket_id order by created_date asc',
            [ 'ticket_id' => $ticket->id ]
        );

        $msgs = [];

        foreach( $dbMsgs as $m )
            $msgs[ $m->id ] = $m;

        return $msgs;
    }

    /**
     * Get any private comments associated with a Cerberus ticket
     */
    private function cerbGetCommentsForTicket( $ticket, $messages )
    {
        $comments = DB::select(
            'SELECT * from comment where context = "cerberusweb.contexts.ticket" and context_id = :ticket_id order by created asc',
            [ 'ticket_id' => $ticket->id ]
        );

        foreach( $messages as $msg ) {
            $mcomments = DB::select(
                'SELECT * from comment where context = "cerberusweb.contexts.message" and context_id = :message_id order by created asc',
                [ 'message_id' => $msg->id ]
            );

            $tcomments = array_merge( $mcomments, $comments );
        }

        return $comments;
    }

    /**
     * Find any attachments associated with a Cerberus message
     */
    private function cerbGetAttachmentsForMessage( $message )
    {
        return DB::select( 'SELECT a.id as id, a.display_name as name, a.storage_key as storage_key
                from attachment a left join attachment_link al on a.id = al.attachment_id
                where al.context = "cerberusweb.contexts.message"
                    and a.display_name != "original_message.html"
                    and al.context_id = :message_id',
            [ 'message_id' => $message->id ]
        );
    }

    /**
     * This function //should// find tickets on Zendesk by a given
     * Cerberus mask. It didn't work. Howveer, searching for tickets
      * in Zendesk by mask works just fine.
     */
    private function zdFindTicketByMask( $mask )
    {
        usleep( 60/200 );
        $exists = $this->zd->tickets()->findAll( ['external_id' => $mask ] );

        if( is_array( $exists ) ) {
            foreach( $exists as $zdticket ) {
                if( $zdticket->external_id == $mask )
                    return $zdticket;
            }
        }

        return false;
    }


    /**
     * We won't query Cerb5 for organisations we've already discovered / created
     * @var array Local cache of Cerb5 organisations
     */
    private $c5orgs = [];

    /**
        * We won't query Zendesk for organisations we've already discovered / created
     * @var array Local cache of Cerb5 organisation IDs to Zendesk organisation IDs
     */
    private $c5orgsTozdorgs = [];

    /**
     * Find / create a Cerb5 organisation in Zendesk
     *
     * The way this works is:
     *
     * - If we've already matched / created a Cerberus organistion to a Zendesk organisation and
     *   we have cached that result, just return the Zendesk organisation ID
     * - Otherwise we search Zendesk by organisation name (and, if found, cache and return the ID)
     * - Otherwise we create the organisation in Zendesk (and cache and return the ID)
     *
     * @param int $org Cerb5 organisation ID
     */
    private function zdResolveOrg( $orgid )
    {
        // if there's no org, just return
        if( !$orgid )
            return null;

        // do we already have a Zendesk org ID for this Cerb5 org?
        if( isset( $this->c5orgsTozdorgs[ $orgid ] ) )
            return $this->c5orgsTozdorgs[ $orgid ];

        // do we have the Cerb5 organisation details?
        if( isset( $c5orgs[ $orgid ] ) ) {
            if( $c5orgs[ $orgid ] )
                $org = $c5orgs[ $orgid ];
            else
                return null;
        }
        else
        {
            $orgs = DB::select( 'select * from contact_org where id = :id', ['id' => $orgid]);

            if( isset( $orgs[0] ) ) {
                $org = $orgs[0];
                $c5orgs[ $orgid ] = $org;
            } else {
                $c5orgs[ $orgid ] = null;
                return null;
            }
        }

        // THE FOLLOWING WAS SPECIFIC TO OUR CASE - COMMENTED AND KEPT FOR REFERENCE ONLY
        //
        // // now have cerb5 organisation, can we find a Zendesk equivalent?
        // //
        // // all our precreated member organisations have a matching external ID from
        // // $ixpcusts:   ( autsys => Zendesk external_id)
        // require( 'ixp-custs.php' );
        //
        // // is it a customer with an asn?
        // preg_match( "/.*\[AS(\d+)\]/", $org->name, $matches );
        //
        // if( isset( $matches[1] ) && isset( $ixpcusts[ $matches[1] ] ) ) {
        //     if( in_array( $matches[1], [ 2128, 43760 ] ) ) {
        //         return null;
        //     }
        //
        //     // now get the organisation by external_id
        //     usleep( 60/200 );
        //     $zdOrgs = $this->zd->organizations()->search( [ 'external_id' => $ixpcusts[ $matches[1] ] ] );
        //
        //     if( isset( $zdOrgs->organizations[0] ) ) {
        //         $c5orgsTozdorgs[ $orgid ] = $zdOrgs->organizations[0]->id;
        //         return $zdOrgs->organizations[0]->id;
        //     }
        // }

        // so we have the Cerberus organisation details, can we find a matching
        // organisation by name in Zendesk?
        usleep( 60/200 );
        $zdOrgs = $this->zd->organizations()->autocomplete( [ 'name' => $org->name ] );

        foreach( $zdOrgs->organizations as $zdOrg ) {
            if( strtoupper( trim( $zdOrg->name ) ) == strtoupper( trim( $org->name ) ) ) {
                $c5orgsTozdorgs[ $orgid ] = $zdOrg->id;
                return $zdOrg->id;
            }
        }

        // So => no organisation on Zendesk yet - create it:
        usleep( 60/200 );
        $zdOrg = $this->zd->organizations()->create([
                'name'    => $org->name
            ])->organization;

        if( is_object( $zdOrg ) ) {
            $c5orgsTozdorgs[ $orgid ] = $zdOrg->id;
            return $zdOrg->id;
        }

        // no org and couldn't create :-(
        $this->error( "\nWe could not create an organisation on Zendesk for: " . $org->name );
        $c5orgsTozdorgs[ $orgid ] = null;
        return null;
    }

    /**
      * We won't query Cerb5 addresses we've already discovered / created
     * @var array Local cache of Cerb5 addresses
     */
    private $c5addresses = [];

    /**
     * We won't query Zendesk for users we've already discovered / created
     * @var array Local cache of Cerb5 address IDs to Zendesk user IDs
     */
    private $c5addressesToZdUsers = [];


    /**
     * Find / create a Cerb5 address as a user in Zendesk
     *
     * The way this works is:
     *
     * - If we've already matched / created a Cerberus user to a Zendesk organisation and
     *   we have cached that result, just return the Zendesk user ID
     * - Otherwise we search Zendesk by email (and, if found, cache and return the ID)
     * - Otherwise we create the user in Zendesk (and cache and return the ID)
     *
     * @param int $addressid Cerb5 address ID
     */
    private function zdResolveAddress( $addressid )
    {
        // if there's no address, just return
        if( !$addressid )
            return null;

        // do we already have a Zendesk user ID for this Cerb5 address?
        if( isset( $this->c5addressesToZdUsers[ $addressid ] ) )
            return $this->c5addressesToZdUsers[ $addressid ];

        // do we have the Cerb5 address details?
        if( isset( $c5addresses[ $addressid ] ) ) {
            if( $c5addresses[ $addressid ] )
                $address = $c5addresses[ $addressid ];
            else
                return null;
        }
        else
        {
            $addresses = DB::select( 'select * from address where id = :id', ['id' => $addressid]);

            if( isset( $addresses[0] ) ) {
                $address = $addresses[0];
                $c5addresses[ $addressid ] = $address;
            } else {
                $c5addresses[ $addressid ] = null;
                return null;
            }
        }

        // You cannot create a user in Zendesk that matches a Zendesk inbound email address
        //
        // Catch those and convert them to something else:
        if( $address->email == 'operations@example.com' )
            $address->email = 'ops@example.com';

        // don't bother sending an invalid email address as Zendesk will reject it
        $validator = Validator::make( [ 'email' => $address->email ], [ 'email' => 'required|email' ] );

        if( $validator->fails() ) {
            $c5addresses[ $addressid ] = null;
            return null;
        }

        // now have cerb5 address, can we find a Zendesk user equivalent?
        usleep( 60/200 );
        $zdUsers = $this->zd->users()->search( [ 'query' => $address->email ] );

        foreach( $zdUsers->users as $zdUser ) {
            if( strtolower( $zdUser->email ) == strtolower( $address->email ) ) {
                $c5addressesToZdUsers[ $addressid ] = $zdUser->id;
                return $zdUser->id;
            }
        }

        // right, no user on Zendesk yet - create it:
        $params = [];

        $name = trim( $address->first_name . ' ' . $address->last_name );
        if( strlen( $name ) ) {
            $params[ 'name' ] = $name;
        }
        else
            $params[ 'name' ] = $address->email;

        $params[ 'email' ] = $address->email;

        usleep( 60/200 );
        $zdUser = $this->zd->users()->create( $params  )->user;

        if( is_object( $zdUser ) ) {
            $c5addressesToZdUsers[ $addressid ] = $zdUser->id;
            return $zdUser->id;
        }

        // no org and couldn't create :-(
        $this->error( "\nWe could not create an user on Zendesk for: " . $address->email );
        $c5addressesToZdUsers[ $addressid ] = null;
        return null;

    }


    /**
     * We only have a handful of agents so we set up the Cerbus agent ID to Zendesk user ID mappings manually:
     */
    private function zdResolveOwner( $ownerid )
    {
        $cerb5ToZdOwners = [
            3  => 777777777,              // cerb agent ID => Zendesk user ID
        ];

        return isset( $cerb5ToZdOwners[ $ownerid ] ) ? $cerb5ToZdOwners[ $ownerid ] : null;
    }

    /**
     * Zendesk say they use ISO8601 datetime formats but they actually require 'Z' at the
     * end rather than a offset. This fixes the timestamp for Zendesk.
     */
    private function fixDate( $a ) {
        return substr( $a, 0, strpos( $a, '+' ) ) . 'Z';
    }
}
