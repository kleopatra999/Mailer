<?php defined('SYSPATH') or die('No direct script access.');

class Mailer_Test extends Mailer {

	public function __construct()
	{
		// Set the config email settings we wish to load.  This must be defined in the config/mailer.php file.
		$this->account = 'default';

		// Construct the parent.
		parent::__construct();
	}

	public function welcome($to, $args=array())
	{
		$this->to 			= $to;
		$this->bcc			= array('admin@theweapp.com' => 'Admin');
		$this->from 		= array('theteam@theweapp.com' => 'The Team');
		$this->subject		= 'Welcome!';
		$this->attachments	= array('/path/to/file/file.txt', '/path/to/file/file2.txt');
		$this->body_data 	= $args;
	}
}
?>