build_bind
==============

This is the module that will enable the ability to extract and build BIND DNS server configurations from the database. It will output the configuration text that would normally be located in something like /etc/bind/named.conf or similar.

Install
-------


  * If you have not already, run the following command `echo '/opt/ona' > /etc/onabase`.  This assumes you installed ONA into /opt/ona 
  * Ensure you have the following prerequisites installed:
    * A BIND DNS server. It is not required to be on the same host as the ONA system.
    * `sendEmail` for notification messages. [Download here](http://caspian.dotconf.net/menu/Software/SendEmail/) or use the package from your distribution.
    * A functioning dcm.pl install on your DHCP server.
  * Download the archive and place it in your $ONABASE/www/local/plugins directory, the directory must be named `build_bind`
  * Make the plugin directory owned by your webserver user I.E.: `chown -R www-data /opt/ona/www/local/plugins/build_bind`
  * From within the GUI, click _Plugins->Manage Plugins_ while logged in as an admin user
  * Click the install icon for the plugin which should be listed by the plugin name 
  * Follow any instructions it prompts you with.
  * Install the $ONABASE/www/local/plugins/build_bind/build_bind script on your DNS server. It is suggested to place it in /opt/ona/bin
  * Modify the variables at the top of the build_bind script to suit your environment.

Usage
-----
At least one host within ONA should be defined as a DNS server for whatever domains you expect it to be responsible for.  The install process above should have also created a system configuration variable called "build_dns_type" with a value of "bind".

You should now see the configuration being built real time in the web interface each time you select the server host and view its DNS server display page.

This now also exposes the dcm.pl module called `build_bind_conf` and `build_bind_domain`.  These will be used by the build_bind script to extract the configuration.  It is also used by the web interface to generate configuration data.

There are a few configuration options in the build script that should be examined.  Edit the file `/opt/ona/bin/build_bind` and adjust the following options as needed:


    # this will default to placing data files in /opt/ona/etc/bind, you can update the following for your system as needed
    # for things like chroot jails etc
    ONA_PATH="${ONABASE}/etc/bind"
    
    # Get the local hosts FQDN.  It will be an assumption!! that it is the same as the hostname in ONA
    # Also, the use of hostname -f can vary from system type to system type.  be aware!
    SRV_FQDN="$(hostname -f)"
    
    # Path to the dcm.pl command.  Also include any options that might be needed
    DCM_PATH="${ONABASE}/bin/dcm.pl"
    
    # Define path for curl binary requires if pulling templates from remote web server
    CURL_PATH="/usr/bin/curl"
    
    # Specify a URL to a directory located on a web server containing domain based
    # footers with additional DNS records to be appended to respective DNS zones.
    # Using this method footer files don't have to be manually synced between
    # name servers. The remote path can be located on the web server that also
    # provided for the OpenNetAdmin instance.
    #
    # It is highly recommended to use HTTPS (SSL/TLS) for transport security but
    # at least ip address based access control e.g. using a htaccess file.
    # When using http basic authentication you can embed the user credentials
    # within the URI like this:
    #
    # # e.g. FOOTER_URL="https://USERNAME:PASSWORD@ipam.mydomain.tld/zone_footers"
    FOOTER_URL="https://USER:PASSWORD@ona.domain.tld/zone_footers" # no trailing slash
    
    # The command used to check the configuration syntax prior to restarting the daemon
    CHECKCOMMAND="named-checkconf -z"
    
    # The command used to restart bind
    # two options would be standard init.d or something like RNDC if it is configured
    # in your environment
    SYSTEMINIT="/etc/init.d/named reload"
    
    # Email settings for config_archive to send status information to (diffs etc)
    MAIL_SERVER=mail.example.com            # name or IP of the mail server to use
    MAIL_FROM=ona-build_dhcpd@${SRV_FQDN}   # email address to use in the from field
    MAIL_TO=hostmaster@example.com          # email address(es) to send our notifications to

Most BIND servers default to using `/etc/bind/named.conf` or similar as their config.  You should make this a symbolic link to `/opt/ona/etc/bind/named.conf.ona` or do an `include` of this config file in your main named.conf. 

On some systems you may need to add the ONA related files to your apparmor or similar security tool.
    
Now that it is installed you should be able to execute `/opt/ona/bin/build_bind` as root.  This will build a configuration file from the data in ONA, test its syntax, and place it into the file `/opt/ona/etc/bind/named.conf.ona`.  When the test is ran it will process configurations built from the database that are stored in /opt/ona/etc/bind.  If it is successful it will restart the BIND server using the init program defined in the `SYSTEMINIT` config variable.  Also set the value of `CHECKCOMMAND` to somethine like `named-config -z` to test the configuration before restarting.

Once you have a successful rebuild of your configuration, you can then put the `/opt/ona/bin/build_bind` build script into a cron that runs at whatever interval you see as appropriate for your environment.  I would suggest at least 2 times a day all the way down to once every 15 minutes.  Remember, you can always run it on demand if needed.  You will need to run it as root since it needs to restart the daemon.

Many modern linux systems use the /etc/cron.d method.  You can put ONA related cron jobs into this directory.  As an example you can create a file called /etc/cron.d/ona with the following content:

    # Please store only OpenNetAdmin related cron entries here.
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin/:/opt/ona/bin
    
    # Rebuild BIND configuration file and restart daemon every hour
    0 * * * * root /opt/ona/bin/build_bind > /dev/null 2>&1

Configuration (version 1.6+)
-----

Since version 1.6 the configuration is no longer embedded within the `build_bind` script itself. Instead it uses a separate config file expected at `${ONABASE}/etc/build_bind.conf`.

Simply copy the sample config `build_bind.conf.sample` to etc/ within your base folder and adjust its parameters to fit your needs.

However, you can provide a custom path by using the `-c <PATH>` option as
well.


Fetching Zone Footers from Remote Web Server
-----

ONA does not yet support DNS records to be placed within zones if
respective records aren't handled by ONA itself.

For instance, it's not possible to add the external MX servers
of Google to a domain managed through ONA. Why this functionality
wasn't implemented yet Matt explains in the following threads:

Non ONA managed CNAMES (external DNS references)
https://github.com/opennetadmin/ona/issues/70

Adding remote host or CNAME - DNS import
http://opennetadmin.com/forum_archive/4/t-65.html

To overcome this limitation tempoarily one can use zone footers
in order to add necessary DNS records per zone e.g. using a script
that's executed right after zones were generated by the build_bind
script.

At the moment `build_dns` tries to implement this using so called
'remote footers'. By simply specifying the `-t` option `build_dns`
can look for domain specific footers within a directory on
a remote web server. Once a match was found the content of
the footer file will be automatically appended to the local zone
of the respective domain.

This way, footers can be kept centrally and there's no need to
manually synchronize them across name servers. This functionality
requires curl to be installed on the target system to work.

To add the global mail exchange servers of Google for `example.com`
To use this functionality you'll first have to create the `/zone_footers`
on the target web server.

    [root@ona ~]# cd /var/www/html/ona/
    [root@ona ona]# mkdir zone_footers
    [root@ona ona]# cat <<'HERE' > zone_footers/example.com.footer
    ; MX Records
    @   1800    IN  MX  10  aspmx.l.google.com
    @   1800    IN  MX  20  alt1.aspmx.l.google.com
    @   1800    IN  MX  30  alt2.aspmx.l.google.com
    @   1800    IN  MX  40  aspmx2.googlemail.com
    @   1800    IN  MX  50  aspmx3.googlemail.com
    HERE

In this example we're deploying the footers on the web server
that is also hosting our ONA instance. This way one can re-use
the .htpasswd file that's used to protect access to the dcm.php
script (you do protect your dcm.php script, right?)

A little out of scope but here's a snippet for a httpd virtual host
containing the directives required to secure your installation
including the footers folder:


    <Files dcm.php>
      Order deny,allow
      # name server ip address
      allow from 10.238.13.8
      allow from localhost
  
      AuthUserFile /opt/ona/www/.htpasswd
      AuthName "dcm access"
      AuthType basic
      Require valid-user
    </Files>
  
    <Location "/zone_footers">
      Order deny,allow
      # name server ip address
      allow from 10.238.13.8
      allow from localhost

      Options Indexes MultiViews FollowSymLinks
      AllowOverride All

      AuthUserFile /opt/ona/www/.htpasswd
      AuthName "footer access"
      AuthType basic
      Require valid-user
    </Location>


Either you re-use the account supposed to create for the `dcm` user or
create a separate one for access to the footers.


    [root@ona ona]# htpasswd /opt/ona/www/.htpasswd footers
    New password: *******
    Re-type new password: ******* 
    Adding password for user footers


On the name server you should now be able to fetch the footer
for zone example.com we've created earlier:


    [root@ns01 ~]# curl -s --output example.com.footer https://footers:MYPASSWORD@ona.domain.tld/zone_footers/example.com.footer
    [root@ns01 ~]# cat example.com.footer
    <MX RECORDS..>


Lets run `build_bind` with the `-t` option and see what happens:


    [root@ns01 ~]# /opt/ona/bin/build_bind -t
    Sep 30 22:51:17 [ONA:build_bind]: INFO => Building BIND DNS config for ns01.example.com...
    Sep 30 22:51:23 [ONA:build_bind]: INFO => Scanning for footers on remote server ...
    Sep 30 22:51:23 [ONA:build_bind]: INFO => Found a match for zone example.com.. appending.
    Sep 30 22:51:26 [ONA:build_bind]: INFO => Testing new config files for SYNTAX only...
    [...]
    Sep 30 23:01:37 [ONA:build_bind]: INFO => Completed BIND configuration
    extraction and daemon reload.

    [root@ns01 ~]# tail -6 /var/named/zone_data/named-example.com 
    ; MX Records
    @   1800    IN  MX  10  aspmx.l.google.com
    @   1800    IN  MX  20  alt1.aspmx.l.google.com
    @   1800    IN  MX  30  alt2.aspmx.l.google.com
    @   1800    IN  MX  40  aspmx2.googlemail.com
    @   1800    IN  MX  50  aspmx3.googlemail.com


Hint: It is highly recommended to implement transport security by
using TLS. In a medium to large scaled deployment, it almost always
makes sense to use certificates issued by a public CA. It is recommended
to use a server that supports Perfect Forward Secrecy such as Apache 2.4
as it is part of CentOS 7.



