<?php
$GroupID = $_GET['groupid'];
if (!is_number($GroupID)) {
    error(404);
}

View::show_header("History for Group $GroupID");

$Groups = Torrents::get_groups([$GroupID], true, true, false);
if (!empty($Groups[$GroupID])) {
    $Group = $Groups[$GroupID];
    $Title = Artists::display_artists($Group['ExtendedArtists']).'<a href="torrents.php?id='.$GroupID.'">'.$Group['Name'].'</a>';
} else {
    $Title = "Group $GroupID";
}
?>

<div class="thin">
    <div class="header">
        <h2>History for <?=$Title?></h2>
    </div>
    <table>
        <tr class="colhead">
            <td>Date</td>
            <td>Torrent</td>
            <td>User</td>
            <td>Info</td>
        </tr>
<?php
    $Log = $DB->query("
            SELECT TorrentID, UserID, Info, Time
            FROM group_log
            WHERE GroupID = $GroupID
            ORDER BY Time DESC");
    $LogEntries = $DB->to_array(false, MYSQLI_NUM);
    foreach ($LogEntries AS $LogEntry) {
        list($TorrentID, $UserID, $Info, $Time) = $LogEntry;
?>
        <tr class="rowa">
            <td><?=$Time?></td>
<?php
            if ($TorrentID != 0) {
                $DB->query("
                    SELECT Media, Format, Encoding
                    FROM torrents
                    WHERE ID = $TorrentID");
                list($Media, $Format, $Encoding) = $DB->next_record();
                if (!$DB->has_results()) { ?>
                    <td><a href="torrents.php?torrentid=<?=$TorrentID?>"><?=$TorrentID?></a> (Deleted)</td><?php
                } elseif ($Media == '') { ?>
                    <td><a href="torrents.php?torrentid=<?=$TorrentID?>"><?=$TorrentID?></a></td><?php
                } else { ?>
                    <td><a href="torrents.php?torrentid=<?=$TorrentID?>"><?=$TorrentID?></a> (<?=$Format?>/<?=$Encoding?>/<?=$Media?>)</td>
<?php           }
            } else { ?>
                <td></td>
<?php       }    ?>
            <td><?=Users::format_username($UserID, false, false, false)?></td>
            <td><?=$Info?></td>
        </tr>
<?php
    }
?>
    </table>
</div>
<?php
View::show_footer();
?>
