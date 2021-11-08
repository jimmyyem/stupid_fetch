<?php
/**
 * 采集脚本
 *
 * 注意事项
 * 1. 如果都没购买的彩票非常好采集
 * 2. 如果比赛已经过期，则采集信息格式应该是改变的，index=0 这样的格式采集起来会出错
 * 3. 已经过期的比赛，不做更新
 **4. 一场比赛有所有的属性，只是不同的玩法体现不同的属性而已******
 * //TODO
 * 采集脚本需要大改，每种玩法一个字段记录，每个比赛一条记录
 */

// 设置
date_default_timezone_set('Asia/Chongqing');

// 引入常用的采集和解析类
require_once('./simple_html_dom.php');
require_once('./Snoopy.php');
require_once('./mysql.php');
require_once('./functions.php');

class Fetch{
    protected $snoopy;
    protected $mongo;
    protected $db;
    protected $collection;

    public function __construct(){
        // 非常重要的一步，因为解析内容多的时候会出现nginx 502 bad gateway
        set_time_limit(999);
        ini_set('memory_limit', '128M');
        
        // 配置snoopy
        $snoopy = new Snoopy();
        $snoopy->agent = rand_agent();
        $snoopy->referer = 'http://caipiao.163.com/';
        $snoopy->cookies['SessionID'] = rand_sessionid();
        $snoopy->rawheaders['X_FORWARDED_FOR'] = rand_ip();
        $snoopy->maxredirs = 4;
        $snoopy->offsiteok = false;
        $snoopy->expandlinks = false;
        $this->snoopy = $snoopy;

        //db连接
        $config = include('../Common/Conf/config.php');
        $this->db = new mysql($config['DB_HOST'], $config['DB_USER'], $config['DB_PWD'], $config['DB_NAME']);

    }

    //开始采集
    public function run(){
        $method = trim($_SERVER['argv'][1]);
        if( method_exists($this, $method) ){
            $this->$method();
        } else {
            exit('method does not exist');
        }
    }

    //胜平负
    public function spf() {
        $appid = 5;
        $url = 'http://caipiao.163.com/order/jczq/spfs.html';
        if(!$this->snoopy->fetch($url)){
            $this->halt('采集信息失败，请通知管理员');
        }

        $html = $this->snoopy->results;
        if(!$html){
            exit('采集信息失败，请通知管理员');
        }
        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);
            //比赛开始和结束时间
            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
                'is_hot'        => $dd->ishot,
            );
            $fetch = array_map('trim', $fetch);

            //主胜，平，主负
            $rate['rang'] = $dd->find('.line1 em', 0)->plaintext;
            $rate['zs'] = $dd->find('.line1 em', 1)->plaintext;
            $rate['p']  = $dd->find('.line1 em', 2)->plaintext;
            $rate['zf'] = $dd->find('.line1 em', 3)->plaintext;
            $rate = array_map('trim', $rate);
            $fetch['spf'] = json_encode($rate);
            //pre($fetch);continue;

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);
            $this->diff($appid, $fetch);
        }
    }

    //进球
    public function jinqiu() {
        $appid = 5;
        $url = 'http://caipiao.163.com/order/jczq-jinqiu/';
        if(!$this->snoopy->fetch($url)){
            $this->halt('采集信息失败，请通知管理员');
        }

        $html = $this->snoopy->results;
        if(!$html){
            exit('采集信息失败，请通知管理员');
        }
        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);
            //比赛开始和结束时间
            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
                'is_hot'        => $dd->ishot,
            );
            $fetch = array_map('trim', $fetch);

            //总进球=主队进球数+客队进球数
            //进球数有如下情况：0,1,2,3,4,5,6,7+
            $s0 = $dd->find('.line1', 0)->find('em', 0)->plaintext;
            $s1 = $dd->find('.line1', 0)->find('em', 1)->plaintext;
            $s2 = $dd->find('.line1', 0)->find('em', 2)->plaintext;
            $s3 = $dd->find('.line1', 0)->find('em', 3)->plaintext;
            $s4 = $dd->find('.line1', 0)->find('em', 4)->plaintext;
            $s5 = $dd->find('.line1', 0)->find('em', 5)->plaintext;
            $s6 = $dd->find('.line1', 0)->find('em', 6)->plaintext;
            $s7 = $dd->find('.line1', 0)->find('em', 7)->plaintext;
            $rate = array(
                's0' => trim($s0),
                's1' => trim($s1),
                's2' => trim($s2),
                's3' => trim($s3),
                's4' => trim($s4),
                's5' => trim($s5),
                's6' => trim($s6),
                's7' => trim($s7)
            );
            $fetch['jinqiu'] = json_encode($rate);
            //dump($fetch);die;

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);

            $this->diff($appid, $fetch);
        }
    }

    //比分
    public function bifen() {
        $appid = 4;
        $url = 'http://caipiao.163.com/order/jczq-bifen/';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;
        
        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $index=>$dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime', 0)->find('i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
                'is_hot'        => $dd->ishot,
            );
            $fetch = array_map('trim', $fetch);

            //主胜，平，主负
            $zs = array(
                    '1b0' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 0)->getAttribute('sp'),
                    '2b0' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 1)->getAttribute('sp'),
                    '2b1' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 2)->getAttribute('sp'),
                    '3b0' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 3)->getAttribute('sp'),
                    '3b1' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 4)->getAttribute('sp'),
                    '3b2' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 5)->getAttribute('sp'),
                    '4b0' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 6)->getAttribute('sp'),
                    '4b1' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 7)->getAttribute('sp'),
                    '4b2' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 8)->getAttribute('sp'),
                    '5b0' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 9)->getAttribute('sp'),
                    '5b1' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 10)->getAttribute('sp'),
                    '5b2' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 11)->getAttribute('sp'),
                    'other' => $div->find('dd.analyMore', $index)->find('tr', 0)->find('td', 12)->getAttribute('sp'),
                );
            $zs = array_map('trim', $zs);
            $p = array(
                    '0b0' => $div->find('dd.analyMore', $index)->find('tr', 1)->find('td', 0)->getAttribute('sp'),
                    '1b1' => $div->find('dd.analyMore', $index)->find('tr', 1)->find('td', 1)->getAttribute('sp'),
                    '2b2' => $div->find('dd.analyMore', $index)->find('tr', 1)->find('td', 2)->getAttribute('sp'),
                    '3b3' => $div->find('dd.analyMore', $index)->find('tr', 1)->find('td', 3)->getAttribute('sp'),
                    'other' => $div->find('dd.analyMore', $index)->find('tr', 1)->find('td', 4)->getAttribute('sp')
                );
            $p = array_map('trim', $p);
            $zf = array(
                    '0b1' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 0)->getAttribute('sp'),
                    '0b2' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 1)->getAttribute('sp'),
                    '1b2' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 2)->getAttribute('sp'),
                    '0b3' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 3)->getAttribute('sp'),
                    '1b3' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 4)->getAttribute('sp'),
                    '2b3' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 5)->getAttribute('sp'),
                    '0b4' =>$div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 6)->getAttribute('sp'),
                    '1b4' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 7)->getAttribute('sp'),
                    '2b4' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 8)->getAttribute('sp'),
                    '0b5' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 9)->getAttribute('sp'),
                    '1b5' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 10)->getAttribute('sp'),
                    '2b5' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 11)->getAttribute('sp'),
                    'other' => $div->find('dd.analyMore', $index)->find('tr', 2)->find('td', 12)->getAttribute('sp')
                );
            $zf = array_map('trim', $zf);
            //记录赔率信息
            $fetch['bifen'] = json_encode( array( 'zs'=>$zs, 'p'=>$p, 'zf'=>$zf ) );

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);

            $this->diff($appid, $fetch);   
        }
    }

    //半全场
    public function banquan() {
        $appid = 7;
        $url = 'http://caipiao.163.com/order/jczq-banquan/';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;

        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
                'is_hot'        => $dd->ishot,
            );
            $fetch = array_map('trim', $fetch);

            //胜胜，胜平，胜负，平胜，平平，平负，负胜，负平，负负
            $ss = $dd->find('.line1 em', 0)->plaintext;
            $sp = $dd->find('.line1 em', 1)->plaintext;
            $sf = $dd->find('.line1 em', 2)->plaintext;
            $ps = $dd->find('.line1 em', 3)->plaintext;
            $pp = $dd->find('.line1 em', 4)->plaintext;
            $pf = $dd->find('.line1 em', 5)->plaintext;
            $fs = $dd->find('.line1 em', 6)->plaintext;
            $fp = $dd->find('.line1 em', 7)->plaintext;
            $ff = $dd->find('.line1 em', 8)->plaintext;
            $rate = array(
                'ss' => $ss,
                'sp' => $sp,
                'sf' => $sf,
                'ps' => $ps,
                'pp' => $pp,
                'pf' => $pf,
                'fs' => $fs,
                'fp' => $fp,
                'ff' => $ff
            );
            $rate = array_map('trim', $rate);
            $fetch['banquan'] = json_encode($rate);
            //dump($fetch);die;

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);

            $this->diff($appid, $fetch);
        }
    }

    //二选一
    public function xuan(){
        $appid = 2;
        $url = 'http://caipiao.163.com/order/jczq-2xuan1/';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;

        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $index=>$dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
                'is_hot'        => $dd->ishot,
            );
            $fetch = array_map('trim', $fetch);

            //主胜，主不胜，主败，主不败
            $rate['s0'] = $this->explode($dd->find('.line1 em', 0)->innertext);
            $rate['s1'] = $this->explode($dd->find('.line1 em', 1)->innertext);
            $rate = array_map('trim', $rate);
            $fetch['xuan'] = json_encode($rate);

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);

            $this->diff($appid, $fetch);
        }
    }

    //猜一场
    public function cai() {
        exit('猜一场不需要做了');
        $appid = 6;
        $url = 'http://caipiao.163.com/order/jczq-dcjs/';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;

        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $index=>$dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd')
            );
            $fetch = array_map('trim', $fetch);

            //主胜，平，主负
            $rate['zs'] = $dd->find('.line1 em', 0)->plaintext;
            $rate['p']  = $dd->find('.line1 em', 1)->plaintext;
            $rate['zf'] = $dd->find('.line1 em', 2)->plaintext;

            $rate = array_map('trim', $rate);
            $fetch['cai'] = json_encode($rate);

            $this->diff($appid, $fetch);
        }
    }

    //让球
    public function rangqiu() {
        $appid = 3;
        $url = 'http://caipiao.163.com/order/jczq/';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;

        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd") as $dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
            );
            $fetch = array_map('trim', $fetch);

            //主胜，平，主负
            if( $dd->find('.co6_1 .line1', 0)->innertext=='未开售' ) { //第一栏是开售，第二栏才有内容
                $line1 = '未开售';
            } else {
                $line1 = array(
                        'rangqiu'   => $dd->find('span.co6_1 div.line1', 0)->find('em', 0)->innertext,
                        'zs'        => $dd->find('span.co6_1 div.line1', 0)->find('em', 1)->innertext,
                        'p'         => $dd->find('span.co6_1',0)->find('.line1', 0)->find('em', 2)->innertext,
                        'zf'        => $dd->find('span.co6_1',0)->find('div.line1', 0)->find('em', 3)->innertext
                    );
            }
            $line1 = array_map('trim', $line1);
            $line2 = array(
                'rangqiu'   => $dd->find('.line2 em.rq', 0)->plaintext,
                'zs'        => $dd->find('.line2 em[index="0"]', 0)->innertext,
                'p'         => $dd->find('.line2 em[index="1"]', 0)->innertext,
                'zf'        => $dd->find('.line2 em[index="2"]', 0)->innertext
            );
            $line2 = array_map('trim', $line2);
            $rate = array('line1'=>$line1, 'line2'=>$line2);
            $fetch['rangqiu'] = json_encode($rate);

            //结果比分
            $co4 = $dd->find('span.co4', 0)->innertext;
            if( stripos($co4, 'finalScore') !== false ){  //有比赛结果
                $score = $dd->find('.finalScore', 0)->innertext;
            }else{  //没有比赛结果
                $score = '';
            }
            $fetch['score'] = trim($score);

            $this->diff($appid, $fetch);
        }
    }

    //单关
    public function danguan() {
        exit('单关是一种玩法，其他类型的赔率组合一起即可');
        $appid = 1;
        $url = 'http://caipiao.163.com/order/jczq-dggd.html';
        if(!$this->snoopy->fetch($url)) {      //开始采集
            $this->halt('采集信息失败，请通知管理员');
        }
        $html = $this->snoopy->results;

        $dom = str_get_html($html);
        $div = $dom->find("div.unAttention", 0);
        foreach ($div->find("dd[isstop=1]") as $index=>$dd) {
            //主场，客场，联赛id，比赛id，联赛名字
            //匹配出具体的时间
            $gameTime = $dd->find('span.gameTime i', 0)->getAttribute('inf');
            list($terminate, $start) = explode('<br/>', $gameTime);
            $start = str_replace('开赛时间：', '', $start);
            $terminate = str_replace('截止时间：', '', $terminate);

            $gametime = $dd->find('span.gameTime', 0)->plaintext;
            $gametime = trim($gametime);
            $fetch = array(
                'matchcode'     => $dd->matchcode, 
                'matchnumcn'    => $dd->matchnumcn, 
                'hostname'      => $dd->hostname, 
                'guestname'     => $dd->guestname, 
                'leagueid'      => $dd->leagueid, 
                'matchid'       => $dd->matchid, 
                'leaguename'    => $dd->leaguename, 
                'gametime'      => $gametime, 
                'game_start'    => strtotime($start.':00'), 
                'game_terminate' => strtotime($terminate.':00'),
                'day'           => date('Ymd'),
            );
            $fetch = array_map('trim', $fetch);

            //让球，主胜，平，主负
            $rate = array(
                'rang'      => $dd->find('.line1', 0)->find('em', 0)->plaintext,
                'zs'        => $dd->find('.line1', 0)->find('em', 1)->innertext,
                'p'         => $dd->find('.line1', 0)->find('em', 2)->innertext,
                'zf'        => $dd->find('.line1', 0)->find('em', 3)->plaintext
            );
            $rate = array_map('trim', $rate);
            $fetch['danguan'] = json_encode($rate);

            $this->diff($appid, $fetch);
        }
    }

    //后期处理
    protected function diff($appid, $fetch) {
        //存入mongodb
        //$toMongo = $fetch;
        //$this->collection->insert($toMongo);
        $fetch['state'] = 0;

        //今天还是明天的信息，是根据matchcode来判断的，他的形式如：
        $condition = array( 'matchcode' => $fetch['matchcode'], 'appid'=>$appid, 'day'=>date('Ymd') );
        //if( substr($fetch['matchcode'], 0, 8) == date('Ymd') ) {
            //上面代码完成了分析和记录功能，下面需要与MySQL中的信息做对比
            //1. 比较内容是否有变化， 比较赔率，也比较可能延迟或者改期的比赛(?)
            //2. 显示的时候判断比赛的结束时间，如果已经过期了则需要把过期的内容状态改为已过期，则不显示该内容
            //根据 day+matchid+leagueid 替换原数据
            $condition = array(
                'day'       => date('Ymd'),
                'matchid'   => $fetch['matchid'],
                'leagueid'  => $fetch['leagueid'],
            );
            $sql = sprintf("SELECT * FROM cp_fetch WHERE matchcode='%s'", $fetch['matchcode']);
            $old = $this->db->get_one($sql);
            if( $old ) {
                //比较是否过期
                if( $old['game_terminate'] < time() ){
                    $fetch['state'] = 1;    //表示已经过期
                }
                //更新内容
                $this->db->update('cp_fetch', $fetch, "id={$old['id']}");
            } else {
                $condition = array(
                    'matchcode'     => $fetch['matchcode'],
                    'hostname'      => $fetch['hostname'],
                    'guestname'     => $fetch['guestname'],
                );
                $sql = sprintf("SELECT * FROM cp_matchcode WHERE matchcode='%s' AND hostname='%s' AND guestname='%s'",
                        $fetch['matchcode'], $fetch['hostname'], $fetch['guestname']
                    );
                $curr = $this->db->get_one($sql);
                if( $curr ){
                    $mcode = $curr['id'];
                } else {
                    $this->db->insert('cp_matchcode', $condition);
                    $mcode = $this->db->insert_id();
                }
                $fetch['mcode'] = $mcode;
                $this->db->insert('cp_fetch', $fetch);
            }
        // } else {        //第二天的信息只插入一次
        //     $sql = sprintf("SELECT * FROM cp_fetch WHERE day='%s' AND matchcode='%s'",
        //             date('Ymd'), $fetch['matchcode']
        //         );
        //     $info = $this->db->get_one($sql);
        //     if(  !$info ){
        //         //插入新信息
        //         $this->db->insert('cp_fetch', $fetch);
        //     }
        // }
    }

    //处理中文+赔率信息
    public function explode($mixed){
        if($mixed=='--') return $mixed;
        if( stripos( $mixed, '<b>' ) === false ) {
            $rate = substr($mixed, 0, 4);
            $chinese = substr($mixed, 4);
        } else {
            $mixed = strip_tags($mixed);
            list($rate, $chinese) = preg_split('/[\s]+/', $mixed);
        }        
        return $chinese.' '.$rate;
    }

    //错误提醒和显示
    protected function halt($errmsg) {
        exit($errmsg);
    }
}

$fetch = new Fetch();
$fetch->run();
