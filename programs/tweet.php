<?php
    $con = mysql_connect(DB_HOST,DB_USER,DB_PASSWORD);
    if (!$con ){
        die('Could not connect: ' . mysql_error());
    }
    mysql_query("set names utf8");
    mysql_select_db(DB_NAME, $con);

    $result = mysql_query("SELECT post_content, post_date FROM wp_posts 
        where ping_status = 'open' and post_status = 'private' order by post_date desc ");
    $float = "right";
    
    $search1 = '/<!-- wp:paragraph -->(.*?)<!-- \/wp:paragraph -->/si';
    $search2 = '/<!-- wp:html -->(.*?)<!-- \/wp:html -->/si';
    while($row = mysql_fetch_array($result)){
        $r = array();
        preg_match_all($search1, $row['post_content'], $r1);
        preg_match_all($search2, $row['post_content'], $r2);
        $l = mb_strlen($r1[1][0]) == 0 ? mb_strlen($r2[1][0])
            :mb_strlen($r1[1][0]) ;
        if ($l > 30) {
            $width = '600px';
        } else if ($l < 8) {
            $width = '160px';
        } else {
            $width = (20*$l).'px';
        }

        echo '<div name="tweet" 
            style="
            width: ' . $width . ';
            clear:both;
            float:' . $float . ';
            padding: 15px;
            margin:30px;
            border-radius: 20px;
            box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2), 0 6px 20px 0 rgba(0, 0, 0, 0.19);
            text-align: left;"><div>' . $row['post_content'] . '</div>';
        echo '<font size=1 style="float:left; margin-left:10px;">' . $row['post_date'] . '</font></div>';
        if ( $float == 'right' ) {
            $float = 'left';
        } else {
            $float = 'right';
        }
    }
    mysql_close($con);
?>