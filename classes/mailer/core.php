<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Core class
 *
 * Provides main functionality for Mailer.
 *
 * This file is part of Mailer.
 *
 * Mailer is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Mailer is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Mailer.  If not, see <http://www.gnu.org/licenses/>.
 */
abstract class Mailer_Core {

	// Release version and codename
	const VERSION  = '1.1';
	const CODENAME = 'phoenix';

	/**
	 * The constant for the default string used to identify with account
	 * is default.
	 *
	 * @var string
	 */
	const SETTING_DEFAULT = 'default';

	/**
	 * The constant that references the settings key for the actual email
	 * accounts defined for a specific account.
	 *
	 * @var string
	 */
	const SETTING_ACCOUNTS = 'accounts';

	/*
	 * Logic settings for the account selector
	 */
	const SETTING_LOGIC = 'logic';

	const SETTING_LOGIC_DEFAULT = 'default'; // In order from top to bottom of the array
	const SETTING_LOGIC_TIMEOFDAY = 'timeofday'; // Based on the time of day will switch to other accounts.

	/*
	 * Defined transports
	 */
	const TRANSPORT_NATIVE = 'native'; // Better known as Mail()
	const TRANSPORT_SENDMAIL = 'sendmail';
	const TRANSPORT_SMTP = 'smtp';

	/**
	 * Prefixes used for __call to tell the system to something but use a callback.
	 */
	const CMD_PREFIX_SEND = 'send_';

	/**
	 * Array of valid transport mechanism.
	 * Add new transports here as code is created.
	 *
	 * @var array
	 */
	protected $valid_transports = array(
		self::TRANSPORT_NATIVE,
		self::TRANSPORT_SENDMAIL,
		self::TRANSPORT_SMTP,
	);

	/**
	 * Array of valid logic methods that can be used to resend failed
	 * email attempts.
	 *
	 * @var array
	 */
	protected $valid_logic = array(
		self::SETTING_LOGIC_DEFAULT,
		self::SETTING_LOGIC_TIMEOFDAY,
	);

	protected $_class_name = null;

	protected $_mailer = null;

	protected $_transport = null;

	protected $_message = null;

	/**
	 * Decide if we should fall back to the default account if we are unable to
	 * find a specific account as defined in APPPATH/config/mailer.php
	 *
	 * @var bool
	 */
	protected $default_fallback = true;

	/**
	 * Which account to load.  If undefined or invalid the mailer will fall
	 * back to 'default'.
	 *
	 * @var string
	 */
	protected $account = null;

	/**
	 * Array of email accounts for the specificed "account"
	 *
	 * @var array
	 */
	protected $accounts = null;

	/**
	 * Loaded config object as defined in APPPATH/config/mailer.php
	 *
	 * @var Kohana_Config Object
	 */
	protected $config = null;

	/**
	 * Send as a batch. Be careful enabling this as the email provider may have
	 * limits on the batch size and/or frequency.
	 *
	 * @var bool
	 */
	protected $batch_send = false;

	/**
	 * Holds the result from the mailing attempt.
	 *
	 * @var mixed
	 */
	protected $result = null;

	/*
	 * Properties for the actual mail message being sent.
	 */
	protected $content_type = 'text/html';

	protected $from = null;

	protected $replyto = null;

	protected $to = null;

	protected $cc = null;

	protected $bcc = null;

	protected $subject = null;

	protected $body_html = null;

	protected $body_text = null;

	protected $body_data = null;

	protected $attachments = null;

	protected $message = null;

	/**
	 * Create a new instance of the proper extended Mailer class.
	 *
	 * @param string $mailer_name
	 */
	public static function factory($mailer_name)
	{
		$class = 'Mailer_'.ucfirst($mailer_name);
		return new $class();
	}

	/**
	 * Parent construct
	 *
	 * @param string $account
	 */
	public function __construct($account = null)
	{
		// Set the account we want to use to mail from.
		$this->account = (!empty($account))?$account:self::SETTING_DEFAULT;

		// Get the string name of the class so we know what to use for file lookups.
		$this->_class_name = get_class($this);

		// Let's init baby
		$this->init();

		// Create the Mailer using a temp transport just to get this thing going.
		// We will override this upon sending.
		return $this->_mailer = Swift_Mailer::newInstance(Swift_MailTransport::newInstance());
	}

	/**
	 * Fire up the actual Mailer class and init all the necessary parts.
	 */
	protected function init()
	{
		// Load up the swiftmailer class as it does not exist.
		if (!class_exists('Swift', false))
		{
			// Load SwiftMailer Autoloader from vendor since it is a module
			require_once Kohana::find_file('vendor', 'swiftmailer/lib/swift_required');
		}

		// Load up the APPPATH/config/mailer.php account settings.
		$this->config = Kohana::config('mailer');

		// Find an account to use from the config file.
		$this->findConfigAccount();

		return true;
	}

	/**
	 * Attempt to find the account we need to load the mailer server(s) settings.
	 *
	 * @throws MailerException
	 */
	protected function findConfigAccount()
	{
		// Try to load up the defined accounts.
		if(isset($this->config->{$this->account}) && !empty($this->config->{$this->account}))
		{
			$this->accounts = $this->config->{$this->account};
		}

		// We have not found an account yet.
		if(is_null($this->accounts))
		{
			// We did not find the account we were looking for so lets load up default, if fallback enabled.
			if($this->default_fallback)
			{
				// Try to load up the default setting.
				if(isset($this->config->{self::SETTING_DEFAULT}) && !empty($this->config->{self::SETTING_DEFAULT}))
				{
					$this->accounts = $this->config->{self::SETTING_DEFAULT};
				}
				else // Throw Exception
				{
					throw new MailerException('Unable to locate "'.self::SETTING_DEFAULT.'" account.', 1);
					return false;
				}
			}
			else // Throw Exception
			{
				throw new MailerException('Unable to locate "'.$this->account.'" account.', 1);
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine if the account setting has multiple email accounts we can send from.
	 */
	protected function hasMultipleEmailAccounts()
	{
		return (count($this->accounts[self::SETTING_ACCOUNTS]) > 1);
	}

	/**
	 * Create a new transport that can be used by the Swift mailer instance.
	 *
	 * @param $options
	 */
	protected function createTransport($options=array())
	{
		switch ($options['transport'])
		{
			case self::TRANSPORT_SMTP:
				// Create SMTP transport
				$transport = Swift_SmtpTransport::newInstance(
							$options['hostname'],
							(empty($options['port']) ? 25 : (int) $options['port']),
							((!empty($options['encryption']))?$options['encryption']:null)
							)->setUsername($options['username'])
							->setPassword($options['password']);
			break;

			case self::TRANSPORT_SENDMAIL:
				// Create Sendmail transport, but can use postfix as well.
				$transport = Swift_SendmailTransport::newInstance((isset($options['cmd']) && !empty($options['cmd']))?$options['cmd']:null);
			break;

			case self::TRANSPORT_NATIVE:
			default: // Create native transport, uses PHP's mail()er
				$transport = Swift_MailTransport::newInstance();
			break;
		}

		// Set default from, can be overwritten as needed.  We only do it if there is not one set.
		if(isset($options['from']) && is_array($options['from']))
		{
			$this->from = $options['from'];
		}

		// Set default reply to, can be overwritten as needed.  We only do it if there is not one set.
		if(isset($options['replyto']) && is_array($options['replyto']))
		{
			$this->replyto = $options['replyto'];
		}

		return $transport;
	}

	/**
	 * Get the email account to attempt to send the email with.
	 *
	 * @param string $logic
	 * @param array $accounts
	 */
	protected function findEmailAccount($logic, Array $accounts=null)
	{
		// We have multiple accounts.
		if($this->hasMultipleEmailAccounts())
		{
			switch($logic)
			{
				case self::SETTING_LOGIC_TIMEOFDAY: // Change email accounts based on time of day.
					$index = intval(date("H") / 24 * count($accounts));
					break;

				case self::SETTING_LOGIC_DEFAULT:
				default:
					$index = 0;
					break;
			}
		}
		else // We only have one account defined, easy enough.
		{
			$index = 0;
		}

		return $index;
	}

	/**
	 * Magic method to snif when to act on certain commands.
	 *
	 * @param $name
	 * @param $args
	 */
	public function __call($name, $args = array())
	{
		// Issued send command.
		if(stristr($name, self::CMD_PREFIX_SEND))
		{
			// Figure out the custom method we need to call in the extending class.
			$method = str_ireplace(self::CMD_PREFIX_SEND, '', $name);

			// See if we have a valid method in the extending class.
			if(method_exists($this, $method))
			{
				// Call the method in the extending class.
				call_user_func_array(array($this, $method), $args);

				// Define the logic we are using.
				if(isset($this->accounts[self::SETTING_LOGIC]) && in_array($this->accounts[self::SETTING_LOGIC], $this->valid_logic))
				{
					$logic = $this->accounts[self::SETTING_LOGIC];
				}
				else
				{
					$logic = self::SETTING_LOGIC_DEFAULT;
				}

				// Define the accounts locally so we can manipulate them.
				$accounts = $this->accounts[self::SETTING_ACCOUNTS];

				// Now lets try to send the actual message.  We loop until we
				// send or run out of account to try.
				while(true)
				{
					try {
						// Now lets pick the account to use.
						$account_index = $this->findEmailAccount($logic, $accounts);

						// Re-define the _mailer.
						$this->_mailer = Swift_Mailer::newInstance(
							$this->createTransport(
								$accounts[$account_index]
							)
						);

						// Setup the message so we can send it.
						$this->setup_message($method);

						// Try to send the message.
						$this->send();

						return $this->result;

					} catch (Swift_TransportException $e) {

						// Lets log
						Kohana::$log->add(Kohana::ERROR, 'Unable to send mail. Error: '.$e->getMessage().' Code:'.$e->getCode());

						// We failed to send the email so let' see if we can try again.
						if(count($accounts) > 0)
						{
							// We need to change the logic because the current one is not working.
							if($logic != self::SETTING_LOGIC_DEFAULT)
							{
								$logic = self::SETTING_LOGIC_DEFAULT;
							}

							// Lets remove the account index from the accounts
							// array because it obviously doesnt work.
							unset($accounts[$account_index]);

							// Reset the accounts array.
							$accounts = array_values($accounts);

							continue; // Taking over the world.
						}
						else // We have no more accounts to test.
						{
							throw new MailerException('Unable to send email. Exhausted all defined accounts.', 10);
							return false;
						}
					}
				}
			}
			else // Invalid method
			{
				throw new MailerException('The method "'.$method.'" is not defined.', 4);
				return false;
			}
		}
		else // Invalid call throw exception.
		{
			throw new MailerException('Unable to handle the __call "'.$name.'"', 3);
			return false;
		}
	}

	/**
	 * setup
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function setup_message($method)
	{
		// Create the message
		$this->message = Swift_Message::newInstance($this->subject);

		//do we need to process the HTML?
		if ($this->content_type == 'text/html' OR $this->content_type == 'multipart/alternative')
		{
			//has it already been set?
			if ($this->body_html === null)
			{
				//find the messsage view
				$base_dir = strtolower(preg_replace('/_/', '/', $this->_class_name));
				$this->body_html = new View($base_dir.'/'.$method);
			}

			//add the body data to it
			if (is_array($this->body_data))
			{
				foreach ($this->body_data as $variable => $data)
				{
					$this->body_html->bind($variable, $this->body_data[$variable]);
				}
			}

			$this->body_html = $this->body_html->render();

			$this->message->setBody($this->body_html, 'text/html');

			if ($this->body_text !== null AND is_string($this->body_text))
			{
				$this->message->setPart($this->body_text, 'text/plain');
			}
		}
		else
		{
			$this->message->setBody($this->body_text, 'text/plain');
		}

		//is there any attachments?
		if ($this->attachments !== null)
		{
			//only one or more?
			if (is_string($this->attachments))
			{
				//Add the attachment
				$this->message->attach(Swift_Attachment::fromPath($this->attachments));
			}
			else if (is_array($this->attachments))
			{
				foreach ($this->attachments as $file)
				{
					//Add the attachment
					$this->message->attach(Swift_Attachment::fromPath($file));
				}
			}
		}

		//set the to field
		if (is_string($this->to))
		{
			$this->to = array($this->to);
		}

		$this->message->setTo($this->to);

		//set the cc field
		if ($this->cc !== null)
		{
			if (is_string($this->cc))
			{
				$this->cc = array($this->cc);
			}
			$this->message->setCc($this->cc);
		}

		//set the bcc field
		if ($this->bcc !== null)
		{
			if (is_string($this->bcc))
			{
				$this->bcc = array($this->bcc);
			}
			$this->message->setBcc($this->bcc);
		}

		//who is it from?
		$this->message->setFrom($this->from);

		// who to reply to?
		if(is_array($this->replyto))
		{
			$this->message->setReplyTo($this->replyto);
		}
		else
		{
			$this->message->setReplyTo($this->from);
		}
	}


	/**
	 * send
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function send()
	{
		//should we batch send or not?
		if (!$this->batch_send)
		{
			//Send the message
			$this->result = (bool)$this->_mailer->send($this->message);
		}
		else
		{
			$this->result = (bool)$this->_mailer->batchSend($this->message);
		}

		return $this->result;
	}

	/**
	 * get_class
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function get_class_name()
	{
		return $this->_class_name;
	}

	/**
	 * get_mailer
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function get_mailer()
	{
		return $this->_mailer;
	}

	/**
	 * set_mailer
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function set_mailer($mailer)
	{
		if ($mailer instanceof Swift_Mailer)
		{
			$this->_mailer = $mailer;
		}
	}

	/**
	 * get_message
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 **/
	public function get_message()
	{
		if ($this->message !== null)
		{
			return $this->message;
		}
	}
}


/**
 * Thrown when Mailer returns an exception.
 */
class MailerException extends Exception {}
?>