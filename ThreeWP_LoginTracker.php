<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: ThreeWP LoginTracker
Plugin URI: http://mindreantre.se/threewp-login-tracker/
Description: Keeps track of all login and login attempts. 
Version: 1.0
Author: Edward Hevlund
Author URI: http://www.mindreantre.se
Author Email: edward@mindreantre.se
*/

if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('ThreeWP_Base_LoginTracker.php');
class ThreeWP_LoginTracker extends ThreeWP_Base_LoginTracker
{
	protected $options = array(
		'role_logins_view'	=>			'administrator',			// Role required to view own logins
		'role_logins_view_other' =>		'administrator',			// Role required to view other users' logins
		'role_logins_delete' =>			'administrator',			// Role required to delete own logins 
		'role_logins_delete_other' =>	'administrator',			// Role required to delete other users' logins
	);
	
	public function __construct()
	{
		parent::__construct(__FILE__);
		define("_3LT", get_class($this));
		register_activation_hook(__FILE__, array(&$this, 'activate') );
		add_filter('wp_login', array(&$this, 'wp_login'), 10, 3);	// Successful logins
		add_filter('wp_login_failed', array(&$this, 'wp_login_failed'), 10, 3);	// Login failures
		add_action('admin_print_styles', array(&$this, 'load_styles') );
		add_action('admin_menu', array(&$this, 'add_menu') );
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Activate / Deactivate
	// --------------------------------------------------------------------------------------------

	public function activate()
	{
		parent::activate();
		
		// If we are on a network site, make the site-admin the default role to access the functions.
		if ($this->isNetwork)
		{
			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->options[$key] = 'site_admin';
		}
		
		$this->register_options();
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_logintracker_logins` (
			`l_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Login ID',
			 `datetime` datetime NOT NULL,
			`user_id` INT NOT NULL COMMENT 'User ID',
			`login_successful` BOOLEAN NOT NULL DEFAULT '0' COMMENT 'Was the login attempt successful',
			`remote_addr` VARCHAR( 15 ) NOT NULL COMMENT 'IP addr of the user',
			`remote_host` VARCHAR( 100 ) NOT NULL COMMENT 'Host of user',
			`user_agent` text NOT NULL COMMENT 'http_user_agent',
			INDEX ( `user_id` )
			) ENGINE = MYISAM COMMENT = 'Logins of the users';
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats` (
		  `user_id` int(11) NOT NULL,
		  `logins_successful` int(11) NOT NULL DEFAULT '0' COMMENT 'How many logins were sucessfull',
		  `logins_failed` int(11) NOT NULL DEFAULT '0' COMMENT 'How many logins failed',
		  `l_id_latest` int(11) DEFAULT NULL COMMENT 'Which login is the latest',
		  PRIMARY KEY (`user_id`)
  			) ENGINE = MYISAM COMMENT = 'Logins of the users';
		");
	}
	
	protected function uninstall()
	{
		$this->deregister_options();
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_logintracker_logins`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`");
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Menus
	// --------------------------------------------------------------------------------------------

	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Callbacks
	// --------------------------------------------------------------------------------------------
	/**
		Logs the successful login of a user.
	*/
	public function wp_login($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlLoginLogSuccess($userdata->ID);
	}
	
	/**
		Logs the unsuccessful login of a user.
	*/
	public function wp_login_failed($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlLoginLogFailure($userdata->ID);
	}
	
	public function add_menu()
	{
		if ($this->role_at_least( $this->get_option('role_logins_view') ))
			add_filter('show_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
			add_filter('personal_options_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
			add_filter('edit_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
			add_filter('edit_user_profile_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
		{
			add_filter('manage_users_columns', array(&$this, 'manage_users_columns')); 
			add_filter('wpmu_users_columns', array(&$this, 'manage_users_columns')); 

			add_filter('manage_users_custom_column', array(&$this, 'manage_users_custom_column'), 10, 3);

			add_submenu_page('index.php', __('Login Tracker', _3LT), __('Login Tracker', _3LT), $this->get_user_role(), 'ThreeWP_LoginTracker', array (&$this, 'user'));
		}

	}
	
	public function load_styles()
	{
		$load = false;
		$load |= strpos($_GET['page'],get_class()) !== false;

		foreach(array('profile.php', 'user-edit.php') as $string)
			$load |= strpos($_SERVER['SCRIPT_FILENAME'], $string) !== false;
		
		if (!$load)
			return;
		
		wp_enqueue_style('3wp_logintracker', '/' . $this->paths['path_from_base_directory'] . '/css/ThreeWP_LoginTracker.css', false, '1.0', 'screen' );
	}
	
	public function manage_users_columns($defaults)
	{
		$defaults['3wp_logintracker'] = '<span title="'.__('Various login statistics about the user', _3LT).'">'.__('Login statistics', _3LT).'</span>';
		return $defaults;
	}
	
	public function manage_users_custom_column($p1, $p2, $p3 = '')
	{
		// echo is the variable that tells us whether we need to echo our returnValue. That's because wpmu... needs stuff to be echoed while normal wp wants stuff returned.
		// *sigh*
		
		if ($p3 == '')
		{
			$column_name = $p1;
			$user_id = $p2;
			$echo = true;
		}
		else
		{
			$column_name = $p2;
			$user_id = $p3;
			$echo = false;
		}
		
		$returnValue = '';
		
		$login_stats = $this->sqlLoginStatsGet($user_id);
		
		if (!is_array($login_stats))
		{
			$message = __('No login data available', _3LT);;
			if ($echo)
				echo $message;
			return $message;
		}
			
		$stats = array();
		
		// Translate the latest login date/time to the user's locale.
		if ($login_stats['l_id_latest'] != '')
		{
			$human_time_diff = human_time_diff(strtotime($login_stats['datetime']), current_time('timestamp'));
			$stats[] = '<span title="'.__('Latest login attempt: ', _3LT).' '.$login_stats['datetime'].'">'. sprintf( __('%s ago'), $human_time_diff) .'</span>';
		}		
		
		$returnValue .= implode(' | ', $stats);
		
		// Show the latest login ip/host.
		if ($login_stats['l_id_latest'] != '')
		{
			$returnValue .= '<br />';
			$returnValue .= $this->makeIP($login_stats, 'html1');
		}
		
		if ($echo)
			echo $returnValue;
		return $returnValue;
	}
	
	public function show_user_profile($userdata)
	{
		$returnValue = '<h3>'.__('Login statistics', _3LT).'</h3>';
		
		$login_stats = $this->sqlLoginStatsget($userdata->ID);
		$returnValue .= '
			<p>
				'.__('Successful logins: ').' '.$login_stats['logins_successful'].'
			</p>
			<p>
				'.__('Failed logins: ').' '.$login_stats['logins_failed'].'
			</p>
		';
		
		$logins = $this->sqlLoginsList($userdata->ID);
		$tBody = '';
		foreach($logins as $login)
		{
			$ip = $this->makeIP($login);
			$tBody .= '
				<tr class="logintracker_login_successful_'.$login['login_successful'].'">
					<td class="screen-reader-text">'. ( $login['login_successful'] ? __('Yes') : __('No') ) .'</td>
					<td>'.$login['datetime'].'</td>
					<td>'.$ip.'</td>
					<td>'.$login['user_agent'].'</td>
				</tr>
			';
		}
		$returnValue .= '
			<table class="widefat">
				<thead>
					<tr>
						<th class="screen-reader-text">'.__('Login successful?', _3LT).'</th>
						<th>'.__('Date and time', _3LT).'</th>
						<th>'.__('IP address', _3LT).'</th>
						<th>'.__('User agent', _3LT).'</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>
		';
		
		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
		{
			$form = $this->form();
			
			// Make crop option
			$inputCrop = array(
				'type' => 'text',
				'name' => '3myl_logins_crop',
				'label' => __('Crop the login list down to this amount of rows', _3LT),
				'value' => count($logins),
				'validation' => array(
					'empty' => true,
				),
			);
			$returnValue .= '<p>'.$form->makeLabel($inputCrop).' '.$form->makeInput($inputCrop).'</p>';

			// Make clear option
			$inputClear = array(
				'type' => 'checkbox',
				'name' => '3myl_logins_delete',
				'label' => __('Clear the list of logins', _3LT),
				'checked' => false,
			);
			$returnValue .= '<p>'.$form->makeInput($inputClear).' '.$form->makeLabel($inputClear).'</p>';
			
		}
		echo $returnValue;
	}
	
	public function personal_options_update($user_id)
	{
		if (!$this->role_at_least( $this->get_option('role_logins_delete') ))
			return;
			
		if (isset($_POST['3myl_logins_delete']))
			$this->sqlLoginsClear($user_id);
			
		$max_logins = intval($_POST['3myl_logins_crop']);
		if ($max_logins > 0)
			$this->sqlLoginsCrop($user_id, $max_logins);
	}

	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Menus
	// --------------------------------------------------------------------------------------------

	public function user()
	{
		$this->loadLanguages(_3LT);
		
		$tabData = array(
			'tabs'		=>	array(),
			'functions' =>	array(),
		);
		
		$tabData['tabs'][] = __('Latest logins', _3LT);
		$tabData['functions'][] = 'userLatestLogins';

		$tabData['tabs'][] = __('User overview', _3LT);
		$tabData['functions'][] = 'userUserOverview';
		
		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
		{
			$tabData['tabs'][] = __('Settings', _3LT);
			$tabData['functions'][] = 'adminSettings';
	
			$tabData['tabs'][] = __('Uninstall', _3LT);
			$tabData['functions'][] = 'adminUninstall';
		}

		$this->tabs($tabData);
	}
	
	public function userLatestLogins()
	{
		$form = $this->form();
		
		$tBody = '';
		$logins = $this->sqlLoginsList('', 100);
		$userCache = array();	// We use a local user cache to speed things up.
		$ago = true;			// Display the login time as "ago" or datetime?
		foreach($logins as $login)
		{
			$user_id = $login['user_id'];
			if (!isset($userCache[$user_id]))
				$userCache[$user_id] = get_userdata( $user_id );
			$user = $userCache[$user_id];
			
			if ($ago)
			{
				$loginTime = strtotime( $login['datetime'] );
				if (time() - $loginTime > 60*60*24)		// Older than 24hrs and we can display the datetime normally.
					$ago = false;
				$human_time_diff = human_time_diff(strtotime($login['datetime']), current_time('timestamp'));
				$time = '<span title="'.$login['datetime'].'">'. sprintf( __('%s ago'), $human_time_diff) .'</span>';
			}
			else
				$time = $login['datetime'];
				
			$tBody .= '
				<tr class="logintracker_login_successful_'.$login['login_successful'].'">
					<td>'.$time.'</td>
					<td><a href="'.$urlUser.'">'.$user->user_login.'</a></td>
					<td>'.$this->makeIP($login).'</td>
					<td>'.$login['user_agent'].'</td>
				</tr>
			';
			
		}
		
		$returnValue = '
			<table class="widefat">
				<thead>
					<tr>
						<th>'.__('Date and time', _3LT).'</th>
						<th>'.__('Username', _3LT).'</th>
						<th>'.__('IP address', _3LT).'</th>
						<th>'.__('User agent', _3LT).'</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>
		';
		
		echo $returnValue;
	}
	
	public function userUserOverview()
	{
		$form = $this->form();
		
		$users = $this->sqlLoginStatsList();
		$userSummary = $this->sqlLoginsSummary();
		$userSummary = $this->array_moveKey($userSummary, 'user_id');
		$tBody = '';
		foreach($users as $user)
		{
			$userdata = get_userdata( $user['user_id'] );
			$tBody .= '
				<tr class="logintracker_login_successful_'.$login['login_successful'].'">
					<td>'.$userdata->user_login.'</td>
					<td>'.$userSummary[ $user['user_id'] ]['count'].'</td>
					<td>'.$user['logins_successful'].'</td>
					<td>'.$user['logins_failed'].'</td>
					<td>'.$user['datetime'].'</td>
				</tr>
			';
		}
		
		$returnValue = '
			<table class="widefat">
				<thead>
					<tr>
						<th>'.__('Username', _3LT).'</th>
						<th>'.__('Logins', _3LT).'</th>
						<th>'.__('Successful logins', _3LT).'</th>
						<th>'.__('Failed logins', _3LT).'</th>
						<th>'.__('Latest login', _3LT).'</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>
		';
		
		echo $returnValue;
	}
	
	public function adminSettings()
	{
		// Collect all the roles.
		$roles = array();
		if ($this->isNetwork)
			$roles['site_admin'] = array('text' => 'Site admin', 'value' => 'site_admin');
		foreach($this->roles as $role)
			$roles[$role['name']] = array('value' => $role['name'], 'text' => ucfirst($role['name']));
			
		if (isset($_POST['3myl_submit']))
		{
			$croprows = intval( $_POST['3myl_logins_crop'] );
			if ($croprows > 0)
				$this->sqlLoginsCrop('', $croprows); 

			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->update_option($key, (isset($roles[$_POST[$key]]) ? $_POST[$key] : 'administrator'));

			$this->message('Options saved!');
		}
		
		$form = $this->form();
		
		$total = 0;
		$summary = $this->sqlLoginsSummary();
		foreach($summary as $row)
			$total += $row['count'];
			
		$inputs = array(
			'logins_crop' => array(
				'type' => 'text',
				'name' => '3myl_logins_crop',
				'label' => __('Crop the login list down to this amount of rows', _3LT),
				'maxlength' => 5,
				'size' => 5,
				'value' => $total,
				'validation' => array(
					'empty' => true,
				),
			),
			'role_logins_view' => array(
				'name' => 'role_logins_view',
				'type' => 'select',
				'label' => 'View own login statistics',
				'value' => $this->get_option('role_logins_view'),
				'options' => $roles,
			),
			'role_logins_view_other' => array(
				'name' => 'role_logins_view_other',
				'type' => 'select',
				'label' => 'View other users\' login statistics',
				'value' => $this->get_option('role_logins_view_other'),
				'options' => $roles,
			),
			'role_logins_delete' => array(
				'name' => 'role_logins_delete',
				'type' => 'select',
				'label' => 'Delete own login statistics',
				'value' => $this->get_option('role_logins_delete'),
				'options' => $roles,
			),
			'role_logins_delete_other' => array(
				'name' => 'role_logins_delete_other',
				'type' => 'select',
				'label' => 'Delete other users\' login statistics and administer the plugin settings',
				'value' => $this->get_option('role_logins_delete_other'),
				'options' => $roles,
			),
		);
		
		$inputSubmit = array(
			'type' => 'submit',
			'name' => '3myl_submit',
			'value' => __('Apply', _3LT),
			'cssClass' => 'button-primary',
		);
			
		$returnValue .= '
			'.$form->start().'
			
			<h3>Database cleanup</h3>
			
			<p>
				There are currently '.$total.' logins in the database.
			</p>
			
			<p>
				'.$form->makeLabel($inputs['logins_crop']).' '.$form->makeInput($inputs['logins_crop']).'
			</p>
			
			<h3>Roles</h3>
			
			<p>
				Actions can be restricted to specific user roles.
			</p>
			
			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_view']).' '.$form->makeInput($inputs['role_logins_view']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_view_other']).' '.$form->makeInput($inputs['role_logins_view_other']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_delete']).' '.$form->makeInput($inputs['role_logins_delete']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_delete_other']).' '.$form->makeInput($inputs['role_logins_delete_other']).'
			</p>

			<p>
				'.$form->makeInput($inputSubmit).'
			</p>
			
			'.$form->stop().'
		';

		echo $returnValue;
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Misc functions
	// --------------------------------------------------------------------------------------------
	
	private function makeIP($login_data, $type = 'text1')
	{
		switch($type)
		{
			case 'text1':
				if ($login_data['remote_host'] != '')
					return $login_data['remote_host'] . ' ('.$login_data['remote_addr'].')';
				else
					return $login_data['remote_addr'];
			break;
			case 'html1':
				if ($login_data['remote_host'] != '')
					return '<span title="'.$login_data['remote_addr'].'">' . $login_data['remote_host'] . '</span>';
				else
					return $login_data['remote_addr'];
			break;
		}
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- SQL
	// --------------------------------------------------------------------------------------------
	private function sqlLoginLogSuccess($user_id)
	{
		$this->sqlLoginLog($user_id, true);
	}
	
	private function sqlLoginLogFailure($user_id)
	{
		$this->sqlLoginLog($user_id, false);
	}

	private function sqlLoginLog($user_id, $success)
	{
		if ($user_id == 0)
			return;
		$this->query("INSERT INTO `".$this->wpdb->base_prefix."_3wp_logintracker_logins` (user_id, login_successful, datetime, remote_addr, remote_host, user_agent) VALUES
			('".$user_id."', '".$success."', now(), '".$_SERVER['REMOTE_ADDR']."', '".$_SERVER['REMOTE_HOST']."', '".$_SERVER['HTTP_USER_AGENT']."')");
		$this->sqlLoginStatsRegenerate($user_id); 
	}
	
	private function sqlLoginsSummary()
	{
		return $this->query("SELECT *, count(*) AS count FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins`
			GROUP BY user_id
			ORDER BY user_id");
	}
	
	private function sqlLoginsList($user_id = '', $limit = 0)
	{
		if ($user_id != '')
			return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins`
				WHERE user_id = '".$user_id."'
				ORDER BY l_id DESC");
		
		return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins`
			ORDER BY l_id DESC
			LIMIT 0, ".$limit );
	}
	
	private function sqlLoginsClear($user_id)
	{
		return $this->query("DELETE FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE user_id = '".$user_id."'");
	}
	
	private function sqlLoginsCrop($user_id, $max)
	{
		if ($user_id != '')
		{
			// Crop only for one user.
			$this->query("DELETE FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE
				user_id = '".$user_id."'
				AND l_id NOT IN (SELECT l_id FROM
					(SELECT * FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE user_id = '".$user_id."' ORDER BY l_id DESC LIMIT 0,".$max.") as temptable
				)");
			$this->sqlLoginStatsRegenerate($user_id);
		}
		else
		{
			// Crop the whole table and regenerate all the stats.
			$query = ("DELETE FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE
				l_id NOT IN
					(SELECT l_id FROM (
						(SELECT l_id FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` ORDER BY l_id DESC LIMIT 0,".$max.") AS temptable )
					)
			");
			
			$this->sqlLoginStatsEmpty();
			
			$rows = $this->query($query);
			$users = $this->query("SELECT DISTINCT user_id FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins`");
			foreach($users as $user)
				$this->sqlLoginStatsRegenerate($user['user_id']);
		}
	}
	
	private function sqlLoginStatsGet($user_id)
	{
		$result = $this->query("SELECT * FROM `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`
			INNER JOIN `".$this->wpdb->base_prefix."_3wp_logintracker_logins` ON (l_id_latest = l_id)
			WHERE `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`.user_id = '".$user_id."'
		");
		if (count($result) !== 1)
			return null;
		else
			return $result[0];
	}
	
	private function sqlLoginStatsList()
	{
		return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`
			INNER JOIN `".$this->wpdb->base_prefix."_3wp_logintracker_logins` ON (l_id_latest = l_id)
			ORDER BY `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`.user_id
		");
	}
	
	private function sqlLoginStatsEmpty()
	{
		$this->query("TRUNCATE TABLE `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats`");
	}
	
	private function sqlLoginStatsRegenerate($user_id)
	{
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats` WHERE user_id = '".$user_id."'"); 
		$this->query("INSERT INTO `".$this->wpdb->base_prefix."_3wp_logintracker_login_stats` (user_id, logins_successful, logins_failed, l_id_latest) VALUES
			(
				'".$user_id."',
				(SELECT COUNT(*) as count FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE user_id = '".$user_id."' AND login_successful = '1'),
				(SELECT COUNT(*) as count FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE user_id = '".$user_id."' AND login_successful = '0'),
				(SELECT MAX(l_id) as count FROM `".$this->wpdb->base_prefix."_3wp_logintracker_logins` WHERE user_id = '".$user_id."')
			)
		");
	}
}

$threewp_logintracker = new ThreeWP_LoginTracker();
?>