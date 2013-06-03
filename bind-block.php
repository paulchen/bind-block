<?php
// TODO
$db_host = 'localhost';
$db_name = 'ns';
$db_user = 'ns';
$db_pass = '';

$db = new PDO("mysql:dbname=$db_name;host=$db_host", $db_user, $db_pass);

function db_query($query, $parameters = array()) {
	global $db;

	if(!($stmt = $db->prepare($query))) {
		$error = $db->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}
	// see https://bugs.php.net/bug.php?id=40740 and https://bugs.php.net/bug.php?id=44639
	foreach($parameters as $key => $value) {
		$stmt->bindValue($key+1, $value, is_numeric($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
	}
	if(!$stmt->execute()) {
		$error = $stmt->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}
	$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
	if(!$stmt->closeCursor()) {
		$error = $stmt->errorInfo();
		db_error($error[2], debug_backtrace(), $query, $parameters);
	}

	return $data;
}

function db_error($error, $stacktrace, $query, $parameters) {
	/* TODO
	global $report_email, $email_from;

	header('HTTP/1.1 500 Internal Server Error');
	echo "A database error has just occurred. Please don't freak out, the administrator has already been notified.\n";

	$params = array(
			'ERROR' => $error,
			'STACKTRACE' => dump_r($stacktrace),
			'QUERY' => $query,
			'PARAMETERS' => dump_r($parameters),
			'REQUEST_URI' => (isset($_SERVER) && isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : 'none',
		);
	send_mail('db_error.php', 'Database error', $params, true);
	*/
}

$blacklist_domains = array_map(function($a) { return $a['domain']; }, db_query('SELECT domain FROM domain_blacklist'));
$whitelist_ips = array_map(function($a) { return $a['ip']; }, db_query('SELECT ip FROM ip_whitelist'));

$already_blocked = array_map(function($a) { return $a['ip']; }, db_query('SELECT ip FROM blocked_ips WHERE unblocked = 0'));

$malicious_ips = array();
foreach($blacklist_domains as $domain) {
	$output = '';
	exec('grep ' . $domain . ' /var/log/bind9/query.log|sed -e "s/.*client //g;s/#.*$//g"|sort|uniq', $output);
	foreach($output as $ip) {
		if(!in_array($ip, $malicious_ips) && !in_array($ip, $already_blocked) && !in_array($ip, $whitelist_ips)) {
			$malicious_ips[] = $ip;
		}
	}
}

$save_ipv4 = false;
$save_ipv6 = false;
if(count($malicious_ips) > 0) {
	foreach($malicious_ips as $ip) {
		if(strpos($ip, ':') !== false) {
			$save_ipv6 = true;
			exec("/sbin/ip6tables -A INPUT -s $ip -p udp -m udp --dport 53 -j DROP", $output);
		}
		else {
			$save_ipv4 = true;
			exec("/sbin/iptables -A INPUT -s $ip -p udp -m udp --dport 53 -j DROP", $output);
		}
		db_query('INSERT INTO blocked_ips (ip) VALUES (?)', array($ip));
	}
}

$data = db_query('SELECT id, ip FROM blocked_ips WHERE DATE_SUB(NOW(), INTERVAL 1 DAY) > timestamp AND unblocked = 0');
foreach($data as $row) {
	$id = $row['id'];
	$ip = $row['ip'];

	if(strpos($ip, ':') !== false) {
		$save_ipv6 = true;
		exec("/sbin/ip6tables -D INPUT -s $ip -p udp -m udp --dport 53 -j DROP", $output);
	}
	else {
		$save_ipv4 = true;
		exec("/sbin/iptables -D INPUT -s $ip -p udp -m udp --dport 53 -j DROP", $output);
	}
	db_query('UPDATE blocked_ips SET unblocked = 1 WHERE id = ?', array($id));
}

if($save_ipv4) {
	exec("/sbin/iptables-save > /etc/iptables.conf");
}
if($save_ipv6) {
	exec("/sbin/ip6tables-save > /etc/iptables6.conf");
}

