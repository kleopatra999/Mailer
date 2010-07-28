<?php defined('SYSPATH') or die('No direct script access.');

class Mailer {

	// Release version and codename
	const VERSION  = '1.0.0';
	const CODENAME = 'phoenix';

	/**
	 * The constant for the default string used to identify with account is default.
	 *
	 * @var string
	 */
	const SETTING_DEFAULT = 'default';

	/**
	 * Prefix used for __call to tell the system to send mail but use a callback.
	 *
	 * @var string
	 */
	const CMD_PREFIX_SEND = 'send_';

	/**
	 * Array of valid transport mechanism.
	 * Add new transports as code is created.
	 *
	 * @var array
	 */
	protected $valid_transports = array('smtp', 'sendmail', 'mail');

	protected $_class_name 	= null;

	protected $_mailer  = null;

	/**
	 * The command to use for sendmail.  If null will use the default one from swift.
	 *
	 * @var string
	 */
	protected $sendmail_cmd 	= null;

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
	protected $account = 'default';

	/**
	 * The object of the specific configuration loaded (i.e. default, accounts, etc...)
	 *
	 * @var Array
	 */
	protected $config = null;

	/**
	 * The object of configurations as defined in config/mailer.php
	 *
	 * @var Kohana_Config_File Object
	 */
	protected $configs = null;

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

	protected $batch_send = false;

	protected $result = null;


	public function __construct($config = null)
	{
		$this->_class_name = get_class($this);

		//setup SwiftMailer
		$this->connect($config);
	}

	/**
	 * Determine which account within an account setting to use for mailing this item.
	 *
	 * @return array
	 */
	protected function getEmailAccount()
	{
		// We have multiple accounts.
		if(count($this->config['accounts']) > 1)
		{
			// @todo: Code this logic and add multiple logic options such as random, in-order, time of day, etc.
			return $this->config['accounts'][0];
		}
		else // We only have one account defined, easy enough.
		{
			return $this->config['accounts'][0];
		}
	}

	/**
	 * factory
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 */
	public static function factory($mailer_name)
	{
		$class = 'Mailer_'.ucfirst($mailer_name);
		return new $class;
	}

	/**
	 * _connect
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
	 */
	public function connect()
	{
		if (!class_exists('Swift', false))
		{
			// Load SwiftMailer Autoloader
			require_once Kohana::find_file('vendor', 'swiftmailer/lib/swift_required');
		}

		// Load up the defined configuration
		$this->configs = Kohana::config('mailer');

		// Now lets check to see if the settings we want exist.
		if(isset($this->configs->{$this->account}) && !empty($this->configs->{$this->account}))
		{
			$this->config = $this->configs->{$this->account};
		}

		// We currently do not have a config defined.
		if(is_null($this->config))
		{
			// We did not find the account we were looking for so lets load up default, if fallback enabled.
			if($this->default_fallback)
			{
				// Try to load up the default setting.
				if(isset($this->configs->{self::SETTING_DEFAULT}) && !empty($this->configs->{self::SETTING_DEFAULT}))
				{
					$this->config = $this->configs->{self::SETTING_DEFAULT};
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

		/*
		 * At this point we should have a mailer config.  Now we need to determine which account to use within the
		 * config incase we have multiple email accounts/methods defined for the account we have selected.
		 */
		$options = $this->getEmailAccount();

		// Do transport
		switch ($options['transport'])
		{
			case 'smtp':
				// Create SMTP transport
				$transport = Swift_SmtpTransport::newInstance(
							$options['hostname'],
							(empty($options['port']) ? 25 : (int) $options['port']),
							((!empty($options['encryption']))?$options['encryption']:null)
							)->setUsername($options['username'])
								->setPassword($options['password']);
			break;

			case 'sendmail':
				// Create Sendmail transport, but can use postfix as well.
				$transport = Swift_SendmailTransport::newInstance((isset($options['cmd']) && !empty($options['cmd']))?$options['cmd']:null);
			break;

			case "mail":
			default: // Create native transport, uses PHP's mail()er
				$transport = Swift_MailTransport::newInstance();
			break;
		}

		// Set default from
		if(isset($options['from']) && is_array($options['from']))
		{
			$this->from = $options['from'];
		}

		// Set default reply to

		if(isset($options['replyto']) && is_array($options['replyto']))
		{
			$this->replyto = $options['replyto'];
		}

		//Create the Mailer using the appropriate transport
		return $this->_mailer = Swift_Mailer::newInstance($transport);
	}

	/**
	 * __call
	 *
	 * @access public
	 * @param  void
	 * @return void
	 *
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

					return true;

				} catch (Swift_TransportException $e) {

				}

				// Mail did not send.
				// @todo: add some fallback stuff here for multiple accounts like attempt to resend email using
				// another account within the same setting.

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
		if($this->replyto !== null)
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
		if ( ! $this->batch_send)
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
}// end of Mailer


/**
 * Thrown when Mailer returns an exception.
 */
class MailerException extends Exception
{
}
?>