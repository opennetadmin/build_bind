<?php

// Get some standard global variables
global $base, $conf, $self, $onadb;

// Gather various bits of information about the plugin
$onainstalldir = dirname($base);
$plugindir = str_replace($onainstalldir.'/www', '', dirname(__FILE__));
$installfile = __FILE__;
$installdir = dirname(__FILE__);
$stat = 0;

// Check permissions
if (!auth('advanced')) {
    $window['js'] = "alert('Permission denied!'); removeElement('{$window_name}');";
    return;
}


//----------------------------Change these values for your plugin---------------------------

// Define this plugins name, must be same as the directory it will live in
$plugin_name = 'build_bind';

// Set a title
$window['title'] = "Build BIND Install";

// Add any DCM module names related to this plugin
// each new module requires a description and a file path name
// the dcm module name is the first field in the array
//
// EXAMPLE
// $pmodules['rack_del']['desc'] = 'Delete a rack';
// $pmodules['rack_del']['file'] = "..{$plugindir}/{$plugin_name}.inc.php";
//
// If you do not specify a file entry, it will default to the path listed in the example
//
$pmodules = array();
$pmodules['build_bind_conf']['desc'] = 'Build BIND DNS config file';
$pmodules['build_bind_conf']['file'] = "..{$plugindir}/{$plugin_name}.inc.php";
$pmodules['build_bind_domain']['desc'] = 'Build BIND DNS zone file';
$pmodules['build_bind_domain']['file'] = "..{$plugindir}/{$plugin_name}.inc.php";
$pmodules['build_bind_server_domain_list']['desc'] = 'List domains associated with a server';
$pmodules['build_bind_server_domain_list']['file'] = "..{$plugindir}/{$plugin_name}.inc.php";

//------------------------------------------------------------------------------------------




// Provide basic javascript for the new popup window
$window['js'] .= <<<EOL
    /* Put a minimize icon in the title bar */
    el('{$window_name}_title_r').innerHTML =
        '&nbsp;<a onClick="toggle_window(\'{$window_name}\');" title="Minimize window" style="cursor: pointer;"><img src="{$images}/icon_minimize.gif" border="0" /></a>' +
        el('{$window_name}_title_r').innerHTML;

    /* Put a help icon in the title bar */
    el('{$window_name}_title_r').innerHTML =
        '&nbsp;<a href="{$_ENV['help_url']}{$window_name}" target="null" title="Help" style="cursor: pointer;"><img src="{$images}/silk/help.png" border="0" /></a>' +
        el('{$window_name}_title_r').innerHTML;

EOL;

$window['html'] .= "<div style='max-height: 500px;max-width:750;overflow: auto;padding: 5px;'>";


if (!is_writable($conf['plugin_dir'])) {
    $window['html'] .= "<br><img src='{$images}/silk/error.png' border='0'><font color=\"red\"> ERROR=> The plugin directory '{$conf['plugin_dir']}' is not writable by the web server!</font><br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;You might execute the command: <font color='orange'>chown -R {$_ENV['APACHE_RUN_USER']} {$conf['plugin_dir']}</font><br>";
    $stat++;
}

// If we have defined modules, process them
if (count($pmodules) > 0 ) {
    $window['html'] .= <<<EOL
<br><b>Installing new DCM modules:</b><br>
EOL;



    // Get list of existing DCM modules to see if they are already installed, Use cache if possible
    if (!is_array($self['cache']['modules']) or !array_key_exists('get_module_list', $self['cache']['modules'])) {
        require_once($conf['dcm_module_dir'] . '/get_module_list.inc.php');
        list($status, $self['cache']['modules']) = get_module_list('type=array');
    }

    // If the new module does not already exist, add it
    foreach ($pmodules as $modname => $attributes) {
        if (!array_key_exists($modname,$self['cache']['modules'])) {
            // default the file location if it is not set to use the main lugin file
            if (!$attributes['file']) $attributes['file'] = "..{$plugindir}/{$plugin_name}.inc.php";
            list($status, $output) = run_module('add_module', array('name' => $modname, 'desc' => $attributes['desc'], 'file' => $attributes['file']));
            if ($status) {
                $stat++;
                $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/error.png' border='0'> {$modname} failed to install.<br>";
            } else {
                printmsg("DEBUG => Plugin install for {$plugin_name} created new DCM module {$modname}.",2);
                $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'> {$modname}<br>";
            }
        } else {
            $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'> {$modname}, already installed.<br>";
        }
    }
}

// If there is a SQL file to process. lets do that
$sqlfile = dirname(__FILE__)."/install.sql";
if (file_exists($sqlfile)) {

    $sqlcontent = file_get_contents($sqlfile);
    $statements = preg_split("/;/", $sqlcontent);

    $has_trans = $onadb->BeginTrans();
    if (!$has_trans) printmsg("WARNING => Transactions support not available on this database, this can cause problems!", 1);

    // If begintrans worked and we support transactions, do the smarter "starttrans" function
    if ($has_trans) {
        printmsg("DEBUG => Starting transaction", 2);
        $onadb->StartTrans();
    }


    // Run the SQL
    printmsg("DEBUG => Installing {$modname} plugin SQL statements.", 4);
    $i = 0;
    while ($i < count($statements)-1) {

        // The SQL statements are split above based on a ; character.
        // This may not always work but should cover most things, just be aware.
        //$window['html'] .= $statements[$i].'---<br><br>';
        $ok = $onadb->Execute($statements[$i].';');
        $error = $onadb->ErrorMsg();

        if ($ok === false or $error) {
            if ($has_trans) {
                printmsg("INFO => There was a module error, marking transaction for a Rollback!", 1);
                $onadb->FailTrans();
            }
            break;
        }
        $i++;
    }

    // Report any errors
    if ($ok === false or $error) {
        $window['html'] .= <<<EOL
        <br><b>Installing database updates:</b><br>
        <img src='{$images}/silk/error.png' border='0'> <font color="red">ERROR => SQL statements failed:</font><br><pre>{$error}</pre>
        <br><img src='{$images}/silk/error.png' border='0'> Unable to automatically process SQL statements<br>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<font color="orange">Please try again, or add the following SQL statements manually:</font>
        <pre>
        {$sqlcontent}
        </pre>
        <br>
        <font color="orange">Possibly use the following command:<br>
        mysql -u {$self['db_login']} -p{$self['db_passwd']} {$self['db_database']} < {$sqlfile}</font><br><br>
EOL;
        $stat++;
    } else {
        $window['html'] .= <<<EOL
        <br><b>Installing database updates:</b><br>
        &nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'> All SQL updates were successful.<br>
EOL;
        if ($has_trans) { $onadb->CompleteTrans(); }
    }

}

// Lets check for files that should be installed.
$window['html'] .= "<br><b>Checking required files:</b><br>";

$reqfile = $onainstalldir.'/bin/build_bind';
if (file_exists($reqfile)) {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'> {$reqfile}<br>";
} else {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/error.png' border='0'> <font color=\"green\">INFO=> Install build script:</font><br>";
    // Provide some information to the user on how to fix the problem manually
    $window['html'] .= "<font color=\"orange\">You will want to install the build_bind script on your BIND DNS server.<br>Download or copy '{$installdir}/build_bind' to /opt/ona/bin/ on your BIND server.</font><br><br>";
}

$reqfile = $onainstalldir.'/bin/dcm.pl';
if (file_exists($reqfile)) {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'> {$reqfile}<br>";
} else {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/error.png' border='0'> <font color=\"green\">INFO=> Unable to find dcm.pl, try the following:</font><br>";
    // Provide some information to the user on how to fix the problem manually
    $window['html'] .= "<font color=\"orange\">Please install <a href=\"http://opennetadmin.com/docs/utils/dcm.pl\">dcm.pl</a>:<br>Make a symlink or install into {$onainstalldir}/bin</font><br><br>";
}

$window['html'] .= "<br><b>Disabling install script:</b><br>";
// If there were no errors, move this install file out of the way.
if (!$stat) {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/accept.png' border='0'>Moved install files.<br><br><center><b>Install complete. Not disabling install script, this way you can always find where the build script is located.<br>Even though this install may look like it failed.. everything is OK to proceed.<br><br>You should read the documentation located <a href='http://opennetadmin.com/docs/plugins/build_bind'>HERE</a> for further install instructions.</b><br><br><a onclick=\"removeElement('{$window_name}');\">CLOSE WINDOW</a><br></center>";
} else {
    $window['html'] .= "&nbsp;&nbsp;&nbsp;&nbsp;<img src='{$images}/silk/error.png' border='0'> Not disabling install script due to previous errors.<br><br><center><a onclick=\"removeElement('{$window_name}');toggle_window('{$window_name}');\">Fix the errors and then click to TRY AGAIN</a></center>";

}

$window['html'] .= "<br><br><center><font color='green'>END OF INSTALL</font></center></div>";

?>
