<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Test (Example) Class
 *
 * Example showing how to make an extending class of Mailer for sending emails
 * using different methods.
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
class Mailer_Test extends Mailer {

	public function __construct()
	{
		// Set the config email settings we wish to load.
		// This must be defined in the config/mailer.php file.
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