build_bind
==============

This is the module that will enable the ability to extract and build BIND DNS server configurations from the database. It will output the configuration text that would normally be located in something like /etc/bind/named.conf or similar.

Install
-------


  * If you have not already, run the following command `echo '/opt/ona' > /etc/onabase`.  This assumes you installed ONA into /opt/ona 
  * Ensure you have the following prerequisites installed:
    * A BIND DNS server. It is not required to be on the same host as the ONA system.
    * `sendEmail` for notification messages.
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
    SRV_FQDN=`hostname -f`
    
    # Path to the dcm.pl command.  Also include any options that might be needed
    DCM_PATH="${ONABASE}/bin/dcm.pl"
    
    # The command used to check the configuration syntax prior to restarting the daemon
    CHECKCOMMAND="named-checkconf -z"
    
    # The command used to restart bind
    # two options would be standard init.d or something like RNDC if it is configured
    # in your environment
    SYSTEMINIT="/etc/init.d/bind9 reload"
    
    # Email settings for config_archive to send status information to (diffs etc)
    MAIL_SERVER=mail.example.com               # name or IP of the mail server to use
    MAIL_FROM=ona-build_dhcpd@$SRV_FQDN        # email address to use in the from field
    MAIL_TO=oncall@example.com                 # email address(es) to send our notifications to

Most BIND servers default to using `/etc/bind/named.conf` or similar as their config.  You should make this a symbolic link to `/opt/ona/etc/bind/named.conf.ona` or do an `include` of this config file in your main named.conf. 

On some systems you may need to add the ONA related files to your apparmor or similar security tool.
    
Now that it is installed you should be able to execute `/opt/ona/bin/build_bind` as root.  This will build a configuration file from the data in ONA, test its syntax, and place it into the file `/opt/ona/etc/bind/named.conf.ona`.  When the test is ran it will process configurations built from the database that are stored in /opt/ona/etc/bind.  If it is successful it will restart the BIND server using the init program defined in the `SYSTEMINIT` config variable.  Also set the value of `CHECKCOMMAND` to somethine like `named-config -z` to test the configuration before restarting.

Once you have a successful rebuild of your configuration, you can then put the `/opt/ona/bin/build_bind` build script into a cron that runs at whatever interval you see as appropriate for your environment.  I would suggest at least 2 times a day all the way down to once every 15 minutes.  Remember, you can always run it on demand if needed.  You will need to run it as root since it needs to restart the daemon.

Many modern linux systems use the /etc/cron.d method.  You can put ONA related cron jobs into this directory.  As an example you can create a file called /etc/cron.d/ona with the following content:

    # Please store only OpenNetAdmin related cron entries here.
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin/:/opt/ona/bin
    
    # Rebuild BIND configuration file and restart daemon every hour
    0 * * * * root /opt/ona/bin/build_bind > /dev/null 2>&1

