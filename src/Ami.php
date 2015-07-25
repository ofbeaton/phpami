<?php
/**
 * Copyright (c) 2004 - 2010 Finlay Beaton <ofbeaton@gmail.com> and others, PHPAGI (pre-fork)
 * All Rights Reserved.
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 *  A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */

namespace PHPAMI;

/**
 * Asterisk Manager class
 *
 * @link    http://www.voip-info.org/wiki-Asterisk+config+manager.conf
 * @link    http://www.voip-info.org/wiki-Asterisk+manager+API
 * @example examples/sip_show_peer.php Get information about a sip peer
 */
class Ami
{
    /**
     * Location of the asterisk directory.
     */
    const AST_CONFIG_DIR = '/etc/asterisk/';

    /**
     * Default configuration file for PHPAMI, in the asterisk directory.
     */
    const DEFAULT_PHPAMI_CONFIG = '/etc/asterisk/phpami.conf';

    /**
     * Default configuration file for PHPAGI, in the asterisk directory.
     *
     * Included for backwards compatibility with PHPAGI library.
     */
    const DEFAULT_PHPAGI_CONFIG = '/etc/asterisk/phpagi.conf';

   /**
    * Config variables
    *
    * @var array
    */
    public $config;

   /**
    * Socket
    *
    * @var resource|null
    */
    public $socket = null;

   /**
    * Server we are connected to
    *
    * @var string
    */
    public $server;

   /**
    * Port on the server we are connected to
    *
    * @var integer
    */
    public $port;

   /**
    * Event Handlers
    *
    * @var array
    */
    private $eventHandlers;

   /**
    * Whether we're successfully logged in
    *
    * @var boolean
    */
    private $loggedIn = false;


   /**
    * Constructor
    *
    * @param string|array $config    Name of the config file to parse.
    * @param array        $optconfig Array of configuration vars and vals, stuffed into $this->config['asmanager'].
    */
    public function __construct($config = null, array $optconfig = [])
    {
        // load config
        if (is_string($config) === true && file_exists($config) === true) {
            $this->config = parse_ini_file($config, true);
        } elseif (file_exists(self::DEFAULT_PHPAMI_CONFIG) === true) {
            $this->config = parse_ini_file(self::DEFAULT_PHPAMI_CONFIG, true);
        } elseif (file_exists(self::DEFAULT_PHPAGI_CONFIG) === true) {
            $this->config = parse_ini_file(self::DEFAULT_PHPAGI_CONFIG, true);
        }

        // If optconfig is specified, stuff vals and vars into 'asmanager' config array.
        foreach ($optconfig as $var => $val) {
            $this->config['asmanager'][$var] = $val;
        }

        // add default values to config for uninitialized values
        if (isset($this->config['asmanager']['server']) === false) {
            $this->config['asmanager']['server'] = 'localhost';
        }

        if (isset($this->config['asmanager']['port']) === false) {
            $this->config['asmanager']['port'] = 5038;
        }

        if (isset($this->config['asmanager']['username']) === false) {
            $this->config['asmanager']['username'] = 'phpagi';
        }

        if (isset($this->config['asmanager']['secret']) === false) {
            $this->config['asmanager']['secret'] = 'phpagi';
        }
    }//end __construct()


   /**
    * Send a request
    *
    * @param string $action     To send.
    * @param array  $parameters To attach.
    *
    * @return array of parameters
    */
    public function sendRequest($action, array $parameters = [])
    {
        $req = 'Action: '.$action."\r\n";
        foreach ($parameters as $var => $val) {
            $req .= $var.': '.$val."\r\n";
        }

        $req .= "\r\n";
        fwrite($this->socket, $req);
        $response = $this->waitResponse();
        return $response;
    }//end sendRequest()


   /**
    * Wait for a response
    *
    * If a request was just sent, this will return the response.
    * Otherwise, it will loop forever, handling events.
    *
    * @param boolean $allowTimeout If the socket times out, return an empty array.
    *
    * @return array of parameters, empty on timeout
    */
    public function waitResponse($allowTimeout = false)
    {
        $timeout = false;
        do {
            $type = null;
            $parameters = [];

            $buffer = trim(fgets($this->socket, 4096));
            while ($buffer != '') {
                $a = strpos($buffer, ':');
                if ($a !== false) {
                    if (count($parameters) === false) { // first line in a response?
                        $type = strtolower(substr($buffer, 0, $a));
                        if (substr($buffer, ($a + 2)) === 'Follows') {
                        // A follows response means there is a miltiline field that follows.
                            $parameters['data'] = '';
                            $buff = fgets($this->socket, 4096);
                            while (substr($buff, 0, 6) != '--END ') {
                                $parameters['data'] .= $buff;
                                $buff = fgets($this->socket, 4096);
                            }
                        }
                    }

                  // store parameter in $parameters
                    $parameters[substr($buffer, 0, $a)] = substr($buffer, ($a + 2));
                }

                $buffer = trim(fgets($this->socket, 4096));
            }//end while

          // process response
            switch ($type) {
                case '':
                     // timeout occured
                    $timeout = $allowTimeout;
                    break;

                case 'event':
                    $this->processEvent($parameters);
                    break;

                case 'response':
                    // nothing to process here
                    break;

                default:
                    $this->log('Unhandled response packet from Manager: ' . print_r($parameters, true));
                    break;
            }
        } while ($type != 'response' && !$timeout);
        return $parameters;
    }//end waitResponse()


   /**
    * Connect to Asterisk
    *
    * @param string|null $server   Hostname to connect to. Recommend FQDN.
    * @param string|null $username Username to authenticate with.
    * @param string|null $secret   Password for the user.
    *
    * @return boolean true on success
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    */
    public function connect($server = null, $username = null, $secret = null)
    {
    // use config if not specified
        if ($server === null) {
            $server = $this->config['asmanager']['server'];
        }

        if ($username === null) {
            $username = $this->config['asmanager']['username'];
        }

        if ($secret === null) {
            $secret = $this->config['asmanager']['secret'];
        }

        // get port from server if specified
        if (strpos($server, ':') !== false) {
            $c = explode(':', $server);
            $this->server = $c[0];
            $this->port = $c[1];
        } else {
            $this->server = $server;
            $this->port = $this->config['asmanager']['port'];
        }

        // connect the socket
        $errno = null;
        $errstr = null;
        // TODO: Convert this to a custom error handler to silence the error instead.
        $this->socket = @fsockopen($this->server, $this->port, $errno, $errstr);
        if ($this->socket === false) {
            $this->log('Unable to connect to manager '.$this->server.':'.$this->port.' ('.$errno.'): '.$errstr);
            return false;
        }

        // read the header
        $str = fgets($this->socket);
        // note: else: don't $this->log($str) until someone looks to see why it mangles the logging
        if ($str === false) {
            // a problem.
            $this->log('Asterisk Manager header not received.');
            return false;
        }

        // login
        $res = $this->sendRequest('login', ['Username' => $username, 'Secret' => $secret]);
        if ($res['Response'] !== 'Success') {
            $this->loggedIn = false;
            $this->log('Failed to login.');
            $this->disconnect();
            return false;
        }

        $this->loggedIn = true;
        return true;
    }//end connect()


   /**
    * Disconnect
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    *
    * @return void
    */
    public function disconnect()
    {
        if ($this->loggedIn === true) {
            $this->logoff();
        }

        fclose($this->socket);
    }//end disconnect()


    /**
     * Set Absolute Timeout
     *
     * Hangup a channel after a certain time.
     *
     * @param string  $channel Channel name to hangup.
     * @param integer $timeout Maximum duration of the call (sec).
     *
     * @return array of parameters
     *
     * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
     */
    public function absoluteTimeout($channel, $timeout)
    {
        $result = $this->sendRequest('AbsoluteTimeout', ['Channel' => $channel, 'Timeout' => $timeout]);
        return $result;
    }//end absoluteTimeout()


   /**
    * Change monitoring filename of a channel
    *
    * @param string $channel The channel to record.
    * @param string $file    The new name of the file created in the monitor spool directory.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
    */
    public function changeMonitor($channel, $file)
    {
        $result = $this->sendRequest('ChangeMontior', ['Channel' => $channel, 'File' => $file]);
        return $result;
    }//end changeMonitor()


   /**
    * Execute Command
    *
    * @param string $command  The command to execute.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    * @link    http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
    * @link    http://www.voip-info.org/wiki-Asterisk+CLI
    */
    public function command($command, $actionId = null)
    {
        $parameters = ['Command' => $command];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('Command', $parameters);
        return $result;
    }//end command()


   /**
    * Enable/Disable sending of events to this manager
    *
    * @param string $eventmask Is either 'on', 'off', or 'system,call,log'.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
    */
    public function events($eventmask)
    {
        $result = $this->sendRequest('Events', ['EventMask' => $eventmask]);
        return $result;
    }//end events()


   /**
    * Check Extension Status
    *
    * @param string $exten    Extension to check state on.
    * @param string $context  Context for extension.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
    */
    public function extensionState($exten, $context, $actionId = null)
    {
        $parameters = [
            'Exten' => $exten,
            'Context' => $context
        ];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('ExtensionState', $parameters);
        return $result;
    }//end extensionState()


   /**
    * Gets a Channel Variable
    *
    * @param string $channel  Channel to read variable from.
    * @param string $variable To retrieve.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
    * @link http://www.voip-info.org/wiki-Asterisk+variables
    */
    public function getVar($channel, $variable, $actionId = null)
    {
        $parameters = [
            'Channel' => $channel,
            'Variable' => $variable
        ];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('GetVar', $parameters);
        return $result;
    }//end getVar()


   /**
    * Hangup Channel
    *
    * @param string $channel The channel name to be hungup.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
    */
    public function hangup($channel)
    {
        $result = $this->sendRequest('Hangup', ['Channel' => $channel]);
        return $result;
    }//end hangup()


   /**
    * List IAX Peers
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
    */
    public function iaxPeers()
    {
        $result = $this->sendRequest('IAXPeers');
        return $result;
    }//end iaxPeers()


   /**
    * List available manager commands
    *
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
    */
    public function listCommands($actionId = null)
    {
        if ($actionId !== null) {
            $result = $this->sendRequest('ListCommands', ['ActionID' => $actionId]);
        } else {
            $result = $this->sendRequest('ListCommands');
        }

        return $result;
    }//end listCommands()


   /**
    * Logoff Manager
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
    */
    public function logoff()
    {
        $result = $this->sendRequest('Logoff');
        return $result;
    }//end logoff()


   /**
    * Check Mailbox Message Count
    *
    * Returns number of new and old messages.
    *   Message: Mailbox Message Count
    *   Mailbox: <mailboxid>
    *   NewMessages: <count>
    *   OldMessages: <count>
    *
    * @param string $mailbox  Full mailbox ID <mailbox>@<vm-context>.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
    */
    public function mailboxCount($mailbox, $actionId = null)
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $results = $this->sendRequest('MailboxCount', $parameters);
        return $results;
    }//end mailboxCount()


   /**
    * Check Mailbox
    *
    * Returns number of messages.
    *   Message: Mailbox Status
    *   Mailbox: <mailboxid>
    *   Waiting: <count>
    *
    * @param string $mailbox  Full mailbox ID <mailbox>@<vm-context>.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
    */
    public function mailboxStatus($mailbox, $actionId = null)
    {
        $parameters = ['Mailbox' => $mailbox];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('MailboxStatus', $parameters);
        return $result;
    }//end mailboxStatus()


   /**
    * Monitor a channel
    *
    * @param string  $channel To monitor.
    * @param string  $file    File.
    * @param string  $format  Format.
    * @param boolean $mix     Mix.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
    */
    public function monitor($channel, $file = null, $format = null, $mix = null)
    {
        $parameters = ['Channel' => $channel];
        if ($file !== null) {
            $parameters['File'] = $file;
        }

        if ($format !== null) {
            $parameters['Format'] = $format;
        }

        if ($file !== null) {
            if ($mix === true) {
                $parameters['Mix'] = 'true';
            } else {
                $parameters['Mix'] = 'false';
            }
        }

        $result = $this->sendRequest('Monitor', $parameters);
        return $result;
    }//end monitor()


   /**
    * Originate Call
    *
    * @param string  $channel     Channel name to call.
    * @param string  $exten       Extension to use (requires 'Context' and 'Priority').
    * @param string  $context     Context to use (requires 'Exten' and 'Priority').
    * @param string  $priority    Priority to use (requires 'Exten' and 'Context').
    * @param string  $application Application to use.
    * @param string  $data        Data to use (requires 'Application').
    * @param integer $timeout     How long to wait for call to be answered (in ms).
    * @param string  $callerid    Caller ID to be set on the outgoing channel.
    * @param string  $variable    Channel variable to set (VAR1=value1|VAR2=value2).
    * @param string  $account     Account code.
    * @param boolean $async       True fast origination.
    * @param string  $actionId    Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Originate
    */
    public function originate(
        $channel,
        $exten = null,
        $context = null,
        $priority = null,
        $application = null,
        $data = null,
        $timeout = null,
        $callerid = null,
        $variable = null,
        $account = null,
        $async = null,
        $actionId = null
    ) {
        $parameters = ['Channel' => $channel];

        if ($exten !== null) {
            $parameters['Exten'] = $exten;
        }

        if ($context !== null) {
            $parameters['Context'] = $context;
        }

        if ($priority !== null) {
            $parameters['Priority'] = $priority;
        }

        if ($application !== null) {
            $parameters['Application'] = $application;
        }

        if ($data !== null) {
            $parameters['Data'] = $data;
        }

        if ($timeout !== null) {
            $parameters['Timeout'] = $timeout;
        }

        if ($callerid !== null) {
            $parameters['CallerID'] = $callerid;
        }

        if ($variable !== null) {
            $parameters['Variable'] = $variable;
        }

        if ($account !== null) {
            $parameters['Account'] = $account;
        }

        if ($async !== null) {
            if ($async === true) {
                $parameters['Async'] = 'true';
            } else {
                $parameters['Async'] = 'false';
            }
        }

        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('Originate', $parameters);
        return $result;
    }//end originate()


   /**
    * List parked calls
    *
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
    */
    public function parkedCalls($actionId = null)
    {
        if ($actionId !== null) {
            $result = $this->sendRequest('ParkedCalls', ['ActionID' => $actionId]);
        } else {
            $result = $this->sendRequest('ParkedCalls');
        }

        return $result;
    }//end parkedCalls()


   /**
    * Ping
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
    */
    public function ping()
    {
        $result = $this->sendRequest('Ping');
        return $result;
    }//end ping()


   /**
    * Queue Add
    *
    * @param string  $queue     Queue.
    * @param string  $interface Interface.
    * @param integer $penalty   Penalty.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
    */
    public function queueAdd($queue, $interface, $penalty = 0)
    {
        $parameters = [
            'Queue' => $queue,
            'Interface' => $interface
        ];
        if ($penalty !== 0) {
            $parameters['Penalty'] = $penalty;
        }

        $result = $this->sendRequest('QueueAdd', $parameters);
        return $result;
    }//end queueAdd()


   /**
    * Queue Remove
    *
    * @param string $queue     Queue.
    * @param string $interface Interface.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
    */
    public function queueRemove($queue, $interface)
    {
        $result = $this->sendRequest('QueueRemove', ['Queue' => $queue, 'Interface' => $interface]);
        return $result;
    }//end queueRemove()


   /**
    * Queues
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
    */
    public function queues()
    {
        $result = $this->sendRequest('Queues');
        return $result;
    }//end queues()


   /**
    * Queue Status
    *
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
    */
    public function queueStatus($actionId = null)
    {
        if ($actionId !== null) {
            $result = $this->sendRequest('QueueStatus', ['ActionID' => $actionId]);
        } else {
            $result = $this->sendRequest('QueueStatus');
        }

        return $result;
    }//end queueStatus()


   /**
    * Redirect
    *
    * @param string $channel      Channel.
    * @param string $extrachannel Extra Channel.
    * @param string $exten        Exten.
    * @param string $context      Context.
    * @param string $priority     Priority.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
    */
    public function redirect($channel, $extrachannel, $exten, $context, $priority)
    {
        $result = $this->sendRequest(
            'Redirect',
            [
                'Channel' => $channel,
                'ExtraChannel' => $extrachannel,
                'Exten' => $exten,
                'Context'  => $context,
                'Priority' => $priority
            ]
        );

        return $result;
    }//end redirect()


   /**
    * Set the CDR UserField
    *
    * @param string $userfield User field.
    * @param string $channel   Channel.
    * @param string $append    Append.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
    */
    public function setCdrUserField($userfield, $channel, $append = null)
    {
        $parameters = [
            'UserField' => $userfield,
            'Channel' => $channel
        ];
        if ($append !== null) {
            $parameters['Append'] = $append;
        }

        $result = $this->sendRequest('SetCDRUserField', $parameters);
        return $result;
    }//end setCdrUserField()


   /**
    * Set Channel Variable
    *
    * @param string $channel  Channel to set variable for.
    * @param string $variable Name.
    * @param string $value    Value.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
    */
    public function setVar($channel, $variable, $value)
    {
        $result = $this->sendRequest('SetVar', ['Channel' => $channel, 'Variable' => $variable, 'Value' => $value]);
        return $result;
    }//end setVar()


   /**
    * Channel Status
    *
    * @param string $channel  Channel.
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
    */
    public function status($channel, $actionId = null)
    {
        $parameters = ['Channel' => $channel];
        if ($actionId !== null) {
            $parameters['ActionID'] = $actionId;
        }

        $result = $this->sendRequest('Status', $parameters);
        return $result;
    }//end status()


   /**
    * Stop monitoring a channel
    *
    * @param string $channel Channel.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
    */
    public function stopMonitor($channel)
    {
        $result = $this->sendRequest('StopMonitor', ['Channel' => $channel]);
        return $result;
    }//end stopMonitor()


   /**
    * Dial over Zap channel while offhook
    *
    * @param string $zapchannel Zap Channel.
    * @param string $number     Number.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
    */
    public function zapDialOffhook($zapchannel, $number)
    {
        $result = $this->sendRequest('ZapDialOffhook', ['ZapChannel' => $zapchannel, 'Number' => $number]);
        return $result;
    }//end zapDialOffhook()


   /**
    * Toggle Zap channel Do Not Disturb status OFF
    *
    * @param string $zapchannel Zap Channel.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
    */
    public function zapDndOff($zapchannel)
    {
        $result = $this->sendRequest('ZapDNDoff', ['ZapChannel' => $zapchannel]);
        return $result;
    }//end zapDndOff()


   /**
    * Toggle Zap channel Do Not Disturb status ON
    *
    * @param string $zapchannel Zap Channel.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
    */
    public function zapDndOn($zapchannel)
    {
        $result = $this->sendRequest('ZapDNDon', ['ZapChannel' => $zapchannel]);
        return $result;
    }//end zapDndOn()


   /**
    * Hangup Zap Channel
    *
    * @param string $zapchannel Zap Channel.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
    */
    public function zapHangup($zapchannel)
    {
        $result = $this->sendRequest('ZapHangup', ['ZapChannel' => $zapchannel]);
        return $result;
    }//end zapHangup()


   /**
    * Transfer Zap Channel
    *
    * @param string $zapchannel Zap Channel.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
    */
    public function zapTransfer($zapchannel)
    {
        $result = $this->sendRequest('ZapTransfer', ['ZapChannel' => $zapchannel]);
        return $result;
    }//end zapTransfer()


   /**
    * Zap Show Channels
    *
    * @param string $actionId Message matching variable.
    *
    * @return array of parameters
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
    */
    public function zapShowChannels($actionId = null)
    {
        if ($actionId !== null) {
            $result = $this->sendRequest('ZapShowChannels', ['ActionID' => $actionId]);
        } else {
            $result = $this->sendRequest('ZapShowChannels');
        }

        return $result;
    }//end zapShowChannels()


   /**
    * Log a message
    *
    * @param string  $message Message to log.
    * @param integer $level   From 1 to 4.
    *
    * @return void
    */
    public function log($message, $level = 1)
    {
        error_log(date('r') . ' - ' . $message);
    }//end log()


   /**
    * Add event handler
    *
    * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
    *   Link - Fired when two voice channels are linked together and voice data exchange commences.
    *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
    *   Newexten -
    *   Hangup -
    *   Newchannel -
    *   Newstate -
    *   Reload - Fired when the "RELOAD" console command is executed.
    *   Shutdown -
    *   ExtensionStatus -
    *   Rename -
    *   Newcallerid -
    *   Alarm -
    *   AlarmClear -
    *   Agentcallbacklogoff -
    *   Agentcallbacklogin -
    *   Agentlogoff -
    *   MeetmeJoin -
    *   MessageWaiting -
    *   join -
    *   leave -
    *   AgentCalled -
    *   ParkedCall - Fired after ParkedCalls
    *   Cdr -
    *   ParkedCallsComplete -
    *   QueueParams -
    *   QueueMember -
    *   QueueStatusEnd -
    *   Status -
    *   StatusComplete -
    *   ZapShowChannels - Fired after ZapShowChannels
    *   ZapShowChannelsComplete -
    *
    * @param string $event    Type or * for default handler.
    * @param string $callback Function.
    *
    * @return boolean sucess
    */
    public function addEventHandler($event, $callback)
    {
        $event = strtolower($event);
        if (isset($this->eventHandlers[$event]) === true) {
            $this->log($event.' handler is already defined, not over-writing.');
            return false;
        }

        $this->eventHandlers[$event] = $callback;
        return true;
    }//end addEventHandler()


   /**
    * Process event
    *
    * @param array $parameters Parameters.
    *
    * @return mixed result of event handler or false if no handler was found
    */
    public function processEvent(array $parameters)
    {
        $ret = false;
        $e = strtolower($parameters['Event']);
        $this->log('Got event: '.$e);

        $handler = '';
        if (isset($this->eventHandlers[$e]) === true) {
            $handler = $this->eventHandlers[$e];
        } elseif (isset($this->eventHandlers['*']) === true) {
            $handler = $this->eventHandlers['*'];
        }

        if (function_exists($handler) === true) {
            $this->log('Execute handler: '.$handler);
            $ret = $handler($e, $parameters, $this->server, $this->port);
        } else {
            $this->log('No event handler for event: '.$e);
        }

        return $ret;
    }//end processEvent()
}//end class