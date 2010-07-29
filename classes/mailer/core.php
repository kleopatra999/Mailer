<?php defined('SYSPATH') or die('No direct script access.');

class Mailer_Core {

	// Release version and codename
	const VERSION  = '1.1';
	const CODENAME = 'phoenix';

	/**
	 * The constant for the default string used to identify with account is default.
	 *
	 * @var string
	 */
	const SETTING_DEFAULT = 'default';

	/**
	 * The constant that references the settings key for the actual email accounts defined for a specific account.
	 *
	 * @var string
	 */
	const SETTING_ACCOUNTS = 'accounts';

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
	protected $valid_transports = array(self::TRANSPORT_NATIVE, self::TRANSPORT_SENDMAIL, self::TRANSPORT_SMTP);

	protected $_class_name = null;

	protected $_mailer = null;

	protected $_transport = null;

	/**
	 * Decide if we should fall back to the default account if we are unable to find a specific account as defined in
	 * config/mailer.php
	 *
	 * @var bool
	 */
	protected $default_fallback = true;

	/**
	 * Which account to load.  If undefined or invalid the mailer will fall back to 'default'.
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
	 * Send as a batch. Be careful enabling this as the email provider may have limits on the batch size and/or frequency.
	 *
	 * @var bool
	 */
	protected $batch_send = false;

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

		// Create the Mailer using the appropriate transport
		return $this->_mailer = Swift_Mailer::newInstance($this->getTransport());
	}

	/**
	 * Fire up the actual Mailer class and init all the necessary parts.
	 */
	protected function init()
	{
		// Load up the swiftmailer class as it does not exist.
		if (!class_exists('Swift', false))
		{
			// Load SwiftMailer Autoloader
			require_once Kohana::find_file('vendor', 'swiftmailer/lib/swift_required');
		}

		// Load up the APPPATH/config/mailer.php account settings.
		$this->config = Kohana::config('mailer');

		// Find an account to use.
		$this->findAccount();

		// Now create the transport.
		$this->setTransport($this->makeTransport($this->getEmailAccount()));

		return true;
	}

	/**
	 * Attempt to find the account we need to send from.
	 *
	 * @throws MailerException
	 */
	protected function findAccount()
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
	 * Return the email account options from a set of accounts.
	 */
	protected function getEmailAccount()
	{
		// We have multiple accounts.
		if(count($this->accounts[self::SETTING_ACCOUNTS]) > 1)
		{
			// @todo: Code this logic and add multiple logic options such as random, in-order, time of day, etc.
			return $this->accounts[self::SETTING_ACCOUNTS][0];
		}
		else // We only have one account defined, easy enough.
		{
			return $this->accounts[self::SETTING_ACCOUNTS][0];
		}
	}

	/**
	 * Set the Swift transport to be used for mailing.
	 *
	 * @param Swift_Transport $transport
	 */
	protected function setTransport(Swift_Transport $transport)
	{
		return $this->_transport = $transport;
	}

	/**
	 * Return the current Swift transport.
	 */
	protected function getTransport()
	{
		return $this->_transport;
	}

	/**
	 * Create a new transport that can be used by the Swift mailer instance.
	 *
	 * @param $options
	 */
	protected function makeTransport($options=array())
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

		// Set default from, can be overwritten as needed.
		if(isset($options['from']) && is_array($options['from']))
		{
			$this->from = $options['from'];
		}

		// Set default reply to, can be overwritten as needed.
		if(isset($options['replyto']) && is_array($options['replyto']))
		{
			$this->replyto = $options['replyto'];
		}

		return $transport;
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
			$method = str_ireplace(self::CMD_PREFIX_SEND, '', $name);

			// See if we have a valid method.
			if(method_exists($this, $method))
			{
				// call the method
				call_user_func_array(array($this, $method), $args);

				//setup the message
				$this->setup_message($method);

				try {
					//send the message
					$this->send();

					return $this->result;

				} catch (Swift_TransportException $e) {

				}

				// Mail did not send.
				// @todo: add some fallback stuff here for multiple accounts like attempt to resend email using
				// another account within the same setting.  Possibly add default fallback, if not default.

				throw new MailerException('Unable to send mail. Error: '.$e->getMessage(), $e->getCode());
				return false;

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
			$this->result = $this->_mailer->send($this->message);
		}
		else
		{
			$this->result = $this->_mailer->batchSend($this->message);
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
class MailerException extends Exception
{
}
?>