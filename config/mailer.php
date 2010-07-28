<?php defined('SYSPATH') or die('No direct script access.');
/**
 * SwiftMailer transports
 *
 * @see http://swiftmailer.org/docs/transport-types
 *
 * Valid transports are: smtp, native, sendmail
 *
 * To use secure connections with SMTP, set "port" to 465 instead of 25.
 * To enable TLS, set "encryption" to "tls".
 * To enable SSL, set "encryption" to "ssl".
 *
 * Transport options:
 * @param   null  	native: no options
 * @param   string  sendmail:
 * @param   array   smtp: hostname, username, password, port, encryption (optional), sendfrom  (optional), replyto  (optional)
 *
 */

return array
(
	'default' => array( // Array of default mailer(s), can use multiple accounts for same section.
		array(
			'transport'	=> 'smtp',
			'hostname'	=> 'localhost',
			'username'	=> 'fakeuser@localhost',
			'password'	=> 'fakepw',
			'port'		=> 25,
			'encryption'=> false,
			'from' 	=> array('fakeuser@localhost' => 'Default Mailer'),
			'replyto' 	=> array('fakeuser@localhost' => 'Default Mailer'),
		),
	),
);

