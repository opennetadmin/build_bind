<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for ".basename(dirname(__FILE__))." plugin.", 1);
    include(dirname(__FILE__)."/install.php");
} else {

// Place initial popupwindow content here if this plugin uses one.


}


// Make sure we have necessary functions & DB connectivity
require_once($conf['inc_functions_db']);







///////////////////////////////////////////////////////////////////////
//  Function: build_bind_server_domain_list (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = build_bind_server_domain_list('server=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//
//
//
///////////////////////////////////////////////////////////////////////
function build_bind_server_domain_list($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.50';

    printmsg("DEBUG => build_bind_server_domain_list({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !$options['server']) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOF

build_bind_server_domain_list-v{$version}
Returns a list of domains associated with the specified server.

  Synopsis: build_bind_server_domain_list [KEY=VALUE] ...

  Required:
    server=NAME[.DOMAIN] or ID      return list for server name or ID

  Notes:
    * Specified host must be a valid DNS server
\n
EOF

        ));
    }

    // Determine the hostname and domain to be used --
    // i.e. add the default domain, or find the part of the host provided
    // that will be used as the "zone" or "domain".  This means testing many
    // zone name's against the DB to see what's valid.
    list($status, $rows, $shost) = ona_find_host($options['server']);
    printmsg("DEBUG => build_bind_server_domain_list() server record: {$domain['server']}", 3);
    if (!$shost['id']) {
        printmsg("DEBUG => Unknown server record: {$options['server']}",3);
        $self['error'] = "ERROR => Unknown server record: {$options['server']}";
        return(array(3, $self['error'] . "\n"));
    }

    // For the given server id. find all domains for that server
    list($status, $rows, $records) = db_get_records($onadb, 'dns_server_domains', array('host_id' => $shost['id']), '');

    //MP: TODO - for now this just returns a list of all the domains.  In the future this could/should just return
    // a list of domains that need refreshed.  This would imply a version to do ALL and one for just UPDATED domains.
    foreach ($records as $sdomain) {
        list($status, $rows, $domain) = ona_get_domain_record(array('id' => $sdomain['domain_id']));
        $text .= $domain['fqdn'] . "\n";
    }

    // Return the list
    return(array(0, $text));
}









///////////////////////////////////////////////////////////////////////
//  Function: build_bind_conf (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = build_bind_conf('server=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//    2  :: No such host
//    3  :: Host is not a DNS server
//    4  :: SQL Query failed
//
//
//  History:
//
//  x/x/06 - Matt Pascoe:
//
//  2/27/06 - Matt Pascoe: adjusted header section to pull headers
//            from the template database and insert them.  The format
//            is to look for templates named "named_header_<hostname>"
//
///////////////////////////////////////////////////////////////////////
function build_bind_conf($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.51';

    printmsg("DEBUG => build_bind_conf({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !($options['server'] and $options['path'])) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOF

build_bind_conf-v{$version}
Builds a named.conf for a dns server from the database (bind 8+)

  Synopsis: build_bind_conf [KEY=VALUE] ...

  Required:
    server=NAME[.DOMAIN] or ID      Build conf by server name or ID
    path=STRING                     Absolute prefix path for local zone files

  Notes:
    * Specified host must be a valid DNS server
    * Paths are absolute but MUST NOT contain a leading /, this will be added.
\n
EOF

        ));
    }


    // NOTE: the whole path absolute thing with no leading slash is confusing I know
    // the problem here is that DCM.pl tries to load the contents of the path you pass in
    // as a file and will always return blank for path.  this is a work around for now
    // until dcm gets fixed.  Maybe fix it by checking if it is a DIR or a FILE.




    // Determine the hostname and domain to be used --
    // i.e. add the default domain, or find the part of the host provided
    // that will be used as the "zone" or "domain".  This means testing many
    // zone name's against the DB to see what's valid.
    list($status, $rows, $shost) = ona_find_host($options['server']);
    printmsg("DEBUG => build_bind_conf() server record: {$domain['server']}", 3);
    if (!$shost['id']) {
        printmsg("DEBUG => Unknown server record: {$options['server']}",3);
        $self['error'] = "ERROR => Unknown server record: {$options['server']}";
        return(array(3, $self['error'] . "\n"));
    }

    // For the given server id. find all domains for that server
    list($status, $rows, $records) = db_get_records($onadb, 'dns_server_domains', array('host_id' => $shost['id']), '');


    // Start building the named.conf - save it in $text
    $text = "# Named.conf file for {$shost['fqdn']} built on " . date($conf['date_format']) . "\n";
    $text .= "# TOTAL DOMAINS (count={$rows})\n\n";

////////////// Header stuff //////////////////

    // Allow for a local header include.. I expect this to rarely be used
    // MP: it is probably best to let the user set up all their own stuff and just include the resulting config
    // file in whatever their own config is.  SOOO no need for this
//    $text .= "; Allow for a local header include.. I expect this to rarely be used.\n";
//    $text .= "include \"/etc/named.conf-header\";\n\n";


////////////// End Header stuff //////////////////

    foreach ($records as $sdomain) {
        list($status, $rows, $domain) = ona_get_domain_record(array('id' => $sdomain['domain_id']));
        // what is the role for this server.
        switch (strtolower($sdomain['role'])) {
            case "forward":
                //TODO: fixme.. this needs IPs like slaves do.. no file
                $text .= "zone \"{$domain['fqdn']}\" in {\n  type forward;\n  file \"/{$options['path']}/named-{$domain['fqdn']}\";}\n";
                break;
            case "master":
                $text .= "zone \"{$domain['fqdn']}\" in {\n  type master;\n  file \"/{$options['path']}/named-{$domain['fqdn']}\";\n};\n\n";
                break;

            case "slave":

                // get the IP addresses for the master domain servers for this domain
                list($status, $rows, $records) = db_get_records($onadb, 'dns_server_domains', array('domain_id' => $domain['id'], 'role' => 'master'), '');

                // TODO: if there are no rows then bail
                // TODO: look for static master list stored in DB and append it to the list.

                $text .= "zone \"{$domain['fqdn']}\" in {\n  type slave;\n  file \"/{$options['path']}/named-{$domain['fqdn']}\";\n";
                // Print the master statement
                $text .= "  masters { ";
                foreach ($records as $master ) {
                    // Lookup a bunch of crap.. this should be done better.
                    list($status, $rows, $rec) = ona_get_host_record(array('id' => $master['host_id']));
                    list($status, $rows, $rec) = ona_get_dns_record(array('id' => $rec['primary_dns_id']));
                    list($status, $rows, $rec) = ona_get_interface_record(array('id' => $rec['interface_id']));
                    $text .= $rec['ip_addr_text']."; ";
                }
                $text .= "};\n";
                $text .= "};\n\n";
                break;

            default:
                $text .= "# {$domain['name']} has an invalid value for the column ROLE.\n";
                break;

        }
    }


    // Return the config file
    return(array(0, $text));

}




///////////////////////////////////////////////////////////////////////
//  Function: build_bind_domain (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = build_zone('zone=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//    2  :: No such host
//
//
//
///////////////////////////////////////////////////////////////////////
function build_bind_domain($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.50';

    printmsg("DEBUG => build_bind_domain({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !$options['domain']) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOF

build_bind_domain-v{$version}
Builds a zone file for a dns server from the database

  Synopsis: build_bind_domain [KEY=VALUE] ...

  Required:
    domain=DOMAIN or ID      build zone file for specified domain

\n
EOF
        ));
    }


    // Get the domain information
    list($status, $rows, $domain) = ona_find_domain($options['domain']);
    printmsg("DEBUG => build_bind_domain() Domain record: {$domain['domain']}", 3);
    if (!$domain['id']) {
        printmsg("DEBUG => Unknown domain record: {$options['domain']}",3);
        $self['error'] = "ERROR => Unknown domain record: {$options['domain']}";
        return(array(2, $self['error'] . "\n"));
    }

    // if for some reason the domains default_ttl is not set, use the one from the $conf['dns']['default_ttl']
    if ($domain['default_ttl'] == 0) $domain['default_ttl'] = $conf['dns']['default_ttl'];

    if ($domain['primary_master'] == '') $domain['primary_master'] = 'localhost';


    // loop through records and display them
    $q="
    SELECT  *
    FROM    dns
    WHERE   domain_id = {$domain['id']}
    ORDER BY type";


    // exectue the query
    $rs = $onadb->Execute($q);
    if ($rs === false or (!$rs->RecordCount())) {
        $self['error'] = 'ERROR => build_zone(): SQL query failed: ' . $onadb->ErrorMsg();
        printmsg($self['error'], 0);
        $exit += 1;
    }
    $rows = $rs->RecordCount();

    // check if this is a ptr domain that has delegation
    if (strpos(str_replace('in-addr.arpa', '', $domain['fqdn']),'-')) {
        $ptrdelegation=true;
    }
    if (strpos(str_replace('ip6.arpa', '', $domain['fqdn']),'-')) {
        $ptrdelegation=true;
    }

    // Start building the named.conf - save it in $text
    $text = "; DNS zone file for {$domain['fqdn']} built on " . date($conf['date_format']) . "\n";

    // print the opening host comment with row count
    $text .= "; TOTAL RECORDS (count={$rows})\n\n";
    // FIXME: MP do more to ensure that dots are at the end as appropriate
    $text .= "\$ORIGIN {$domain['fqdn']}.\n";
    $text .= "\$TTL {$domain['default_ttl']}\n";
    $text .= ";Serial number is current unix timestamp (seconds since UTC)\n\n";

    // NOTE: There are various ways that one could generate the serial.  The bind book suggests YYYYMMDDXX where XX is 1/100th of the day or some counter in the day.
    // I feel this is too limiting.  I prefer the Unix timestamp (seconds since UTC) method.  TinyDNS uses this method as well and it allows for much more granularity.
    // Referr to the following for some discussion on the topic: http://www.lifewithdjbdns.com/#Migration
    // NOTE: for now I am generating the serial each time the zone is built.  I'm ignoring, and may remove, the one stored in the database.
    $serial_number = time();

    // Build the SOA record
    // FIXME: MP do a bit more to ensure that dots are where they should be
    $text .= "@      IN      SOA   {$domain['primary_master']}. {$domain['admin_email']} ({$serial_number} {$domain['refresh']} {$domain['retry']} {$domain['expiry']} {$domain['minimum']})\n\n";


    // Loop through the record set
    while ($dnsrecord = $rs->FetchRow()) {
        // Dont build records that begin in the future
        if (strtotime($dnsrecord['ebegin']) > time()) continue;
        if (strtotime($dnsrecord['ebegin']) < 0) continue;

        // If there are notes, put the comment character in front of it
        if ($dnsrecord['notes']) $dnsrecord['notes'] = '; '.$dnsrecord['notes'];

        // If the ttl is empty then make it truely empty
        if ($dnsrecord['ttl'] == 0) $dnsrecord['ttl'] = '';

        // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
        if (!strcmp($dnsrecord['ttl'],$domain['default_ttl'])) $dnsrecord['ttl'] = '';

        // Dont print a dot unless hostname has a value
        if ($dnsrecord['name']) $dnsrecord['name'] = $dnsrecord['name'].'.';

        if ($dnsrecord['type'] == 'A') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Determine A record type if it is IPv6
            $dnsrecord['type'] = (strpos($int['ip_addr_text'],':') ? 'AAAA' : 'A');

            $fqdn = $dnsrecord['name'].$domain['fqdn'];
            $text .= sprintf("%-50s %-8s IN  %-8s %-30s %s\n" ,$fqdn.'.',$dnsrecord['ttl'],$dnsrecord['type'],$interface['ip_addr_text'],$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'PTR') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $ptr) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            // set the ptr zone type for IPv6 records
            $arpatype = (strpos($int['ip_addr_text'],':') ? 'ip6' : 'in-addr');

            // If this is a delegation domain, find the subnet cidr
            if ($ptrdelegation) {
                list($status, $rows, $subnet) = ona_get_subnet_record(array('id' => $interface['subnet_id']));

                $ip_last = ip_mangle($interface['ip_addr'],'flip');
                $ip_last_digit = substr($ip_last, 0, strpos($ip_last,'.'));
                $ip_remainder  = substr($ip_last, strpos($ip_last,'.')).".${arpatype}.arpa.";
                $text .= sprintf("%-50s %-8s IN  %-8s %s.%-30s %s\n" ,$ip_last_digit.'-'.ip_mangle($subnet['ip_mask'],'cidr').$ip_remainder,$dnsrecord['ttl'],$dnsrecord['type'],$ptr['name'],$ptr['domain_fqdn'].'.',$dnsrecord['notes']);
            } else {
                $text .= sprintf("%-50s %-8s IN  %-8s %s.%-30s %s\n" ,ip_mangle($interface['ip_addr'],'flip').".${arpatype}.arpa.",$dnsrecord['ttl'],$dnsrecord['type'],$ptr['name'],$ptr['domain_fqdn'].'.',$dnsrecord['notes']);
            }
        }

        if ($dnsrecord['type'] == 'CNAME') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $cname) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            $fqdn = $dnsrecord['name'].$domain['fqdn'];
            $text .= sprintf("%-50s %-8s IN  %-8s %s.%-30s %s\n" ,$fqdn.'.',$dnsrecord['ttl'],$dnsrecord['type'],$cname['name'],$cname['domain_fqdn'].'.',$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'NS') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $ns) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            $text .= sprintf("%-50s %-8s IN  %-8s %s.%-30s %s\n" ,$domain['fqdn'].'.',$dnsrecord['ttl'],$dnsrecord['type'],$ns['name'],$ns['domain_fqdn'].'.',$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'MX') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $mx) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            if ($dnsrecord['name']) {
                $name = $dnsrecord['name'].$domain['fqdn'];
            }
            else {
                $name = $domain['name'];
            }
            $text .= sprintf("%-50s %-8s IN  %s %-5s %s.%-30s %s\n" ,$name.'.',$dnsrecord['ttl'],$dnsrecord['type'],$dnsrecord['mx_preference'],$mx['name'],$mx['domain_fqdn'].'.',$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'SRV') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => Unable to find interface record!",3);
                $self['error'] = "ERROR => Unable to find interface record!";
                return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $srv) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            if ($dnsrecord['name']) {
                $name = $dnsrecord['name'].$domain['fqdn'];
            }
            else {
                $name = $domain['name'];
            }
            $text .= sprintf("%-50s %-8s IN  %s %s %s %-8s %-30s %s\n" ,$name.'.',$dnsrecord['ttl'],$dnsrecord['type'],$dnsrecord['srv_pri'],$dnsrecord['srv_weight'],$dnsrecord['srv_port'],$srv['fqdn'].'.',$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'TXT') {
            $fqdn = $dnsrecord['name'].$domain['fqdn'];
            $text .= sprintf("%-50s %-8s IN  %-8s %-30s %s\n" ,$fqdn.'.',$dnsrecord['ttl'],$dnsrecord['type'],'"'.$dnsrecord['txt'].'"',$dnsrecord['notes']);
        }
    }







////////////// Footer stuff //////////////////

    // MP: FIXME: For now I"m not using this.. bind errors out if the file doesnt exist.  need a deterministic way to do this.   
    // Allow for a local footer include.. I expect this to rarely be used
//    $text .= "\n; Allow for a local footer include.. I expect this to rarely be used.\n";
//    $text .= "\$INCLUDE named-{$domain['fqdn']}-footer\n";




    // Return the zone file
    return(array(0, $text));


}




?>
