<?php

namespace Gazelle;

class Bonus extends Base {
    const CACHE_ITEM = 'bonus_item';
    const CACHE_OPEN_POOL = 'bonus_pool';
    const CACHE_SUMMARY = 'bonus_summary_';
    const CACHE_HISTORY = 'bonus_history_';
    const CACHE_POOL_HISTORY = 'bonus_pool_history_';

    protected $items;

    public function __construct() {
        parent::__construct();
        $this->items = $this->cache->get_value(self::CACHE_ITEM);
        if ($this->items === false) {
            $this->db->prepared_query("
                SELECT ID, Price, Amount, MinClass, FreeClass, Label, Title, sequence,
                    IF (Label REGEXP '^other-', 'ConfirmOther', 'null') AS JS_next_function,
                    IF (Label REGEXP '^title-bb-[yn]', 'NoOp', 'ConfirmPurchase') AS JS_on_click
                FROM bonus_item
                ORDER BY sequence
            ");
            $this->items = $this->db->has_results() ? $this->db->to_array('Label') : [];
            $this->cache->cache_value(self::CACHE_ITEM, $this->items, 86400 * 30);
        }
    }

    public function flushUserCache($userId) {
        $this->cache->deleteMulti([
            'user_info_heavy_' . $userId,
            'user_stats_' . $userId,
        ]);
    }

    public function getList() {
        return $this->items;
    }

    public function getListForUser(int $userId) {
        $items = [];
        foreach ($this->items as $item) {
            $item['Price'] = $this->getEffectivePrice($item['Label'], $userId);
            $items[] = $item;
        }
        return $items;
    }

    public function getItem($label) {
        return array_key_exists($label, $this->items) ? $this->items[$label] : null;
    }

    public function getTorrentValue($format, $media, $encoding, $haslogdb = 0, $logscore = 0, $logchecksum = 0) {
        if ($format == 'FLAC') {
            if ($media == 'CD' && $haslogdb && $logscore === 100 && $logchecksum == 1) {
                return BONUS_AWARD_FLAC_PERFECT;
            }
            elseif (in_array($media, ['Vinyl', 'WEB', 'DVD', 'Soundboard', 'Cassette', 'SACD', 'Blu-ray', 'DAT'])) {
                return BONUS_AWARD_FLAC_PERFECT;
            }
            else {
                return BONUS_AWARD_FLAC;
            }
        }
        elseif ($format == 'MP3' && in_array($encoding, ['V0 (VBR)', '320'])) {
            return BONUS_AWARD_MP3;
        }
        return BONUS_AWARD_OTHER;
    }

    public function getEffectivePrice($label, $userId) {
        $item  = $this->items[$label];
        $info = \Users::user_heavy_info($userId);
        if (preg_match('/^collage-\d$/', $label)) {
            return $item['Price'] * pow(2, $info['Collages']);
        }

        $info = \Users::user_info($userId);
        return $info['EffectiveClass'] >= $item['FreeClass'] ? 0 : $item['Price'];
    }

    public function getListOther($balance) {
        $list_other = [];
        foreach ($this->items as $label => $item) {
            if (preg_match('/^other-\d$/', $label) && $balance >= $item['Price']) {
                $list_other[] = [
                    'Label' => $item['Label'],
                    'Name'  => $item['Title'],
                    'Price' => $item['Price'],
                    'After' => $balance - $item['Price'],
                ];
            }
        }
        return $list_other;
    }

    public function getOpenPool() {
        $key = self::CACHE_OPEN_POOL;
        if (($pool = $this->cache->get_value($key)) === false) {
            $this->db->prepared_query("
                SELECT bonus_pool_id, name, total
                FROM bonus_pool
                WHERE now() BETWEEN since_date AND until_date
                ORDER BY since_date
                LIMIT 1
            ");
            $pool = $this->db->next_record(MYSQLI_ASSOC);
            $this->cache->cache_value($key, $pool, 3600);
        }
        return $pool ?? [];
    }

    public function donate(int $poolId, int $value, int $userId, int $effectiveClass) {
        if ($effectiveClass < 250) {
            $taxedValue = $value * BONUS_POOL_TAX_STD;
        }
        elseif($effectiveClass == 250 /* Elite */) {
            $taxedValue = $value * BONUS_POOL_TAX_ELITE;
        }
        elseif($effectiveClass <= 500 /* EliteTM */) {
            $taxedValue = $value * BONUS_POOL_TAX_TM;
        }
        else {
            $taxedValue = $value * BONUS_POOL_TAX_STAFF;
        }

        if (!$this->removePoints($userId, $value)) {
            return false;
        }
        (new BonusPool($poolId))->contribute($userId, $value, $taxedValue);

        $this->cache->deleteMulti([
            self::CACHE_OPEN_POOL,
            self::CACHE_POOL_HISTORY . $userId,
            'user_info_heavy_' . $userId,
            'user_stats_' . $userId,
        ]);
        return true;
    }

    public function getUserSummary($userId) {
        $key = self::CACHE_SUMMARY . $userId;
        $summary = $this->cache->get_value($key);
        if ($summary === false) {
            $this->db->prepared_query('
                SELECT count(*) AS nr, coalesce(sum(price), 0) AS total FROM bonus_history WHERE UserID = ?
                ', $userId
            );
            $summary = $this->db->next_record(MYSQLI_ASSOC);
            $this->cache->cache_value($key, $summary, 86400 * 7);
        }
        return $summary;
    }

    public function getUserHistory($userId, $page, $itemsPerPage) {
        $key = self::CACHE_HISTORY . "{$userId}.{$page}";
        $history = $this->cache->get_value($key);
        if ($history === false) {
            $this->db->prepared_query('
                SELECT i.Title, h.Price, h.PurchaseDate, h.OtherUserID
                FROM bonus_history h
                INNER JOIN bonus_item i ON (i.ID = h.ItemID)
                WHERE h.UserID = ?
                ORDER BY PurchaseDate DESC
                LIMIT ? OFFSET ?
                ', $userId, $itemsPerPage, $itemsPerPage * ($page-1)
            );
            $history = $this->db->has_results() ? $this->db->to_array() : null;
            $this->cache->cache_value($key, $history, 86400 * 3);
            /* since we had to fetch this page, invalidate the next one */
            $this->cache->delete_value(self::CACHE_HISTORY . $userId . ($page+1));
        }
        return $history;
    }

    public function getUserPoolHistory($userId) {
        $key = self::CACHE_POOL_HISTORY . $userId;
        $history = $this->cache->get_value($key);
        if ($history === false) {
            $this->db->prepared_query('
                SELECT sum(c.amount_recv) AS total, p.until_date, p.name
                FROM bonus_pool_contrib c
                INNER JOIN bonus_pool p USING (bonus_pool_id)
                WHERE c.user_id = ?
                GROUP BY p.until_date, p.name
                ORDER BY p.until_date, p.name
                ', $userId
            );
            $history = $this->db->has_results() ? $this->db->to_array() : null;
            $this->cache->cache_value($key, $history, 86400 * 3);
        }
        return $history;
    }

    public function purchaseInvite($userId) {
        $item = $this->items['invite'];
        $price = $item['Price'];
        if (!\Users::canPurchaseInvite($userId, $item['MinClass'])) {
            throw new \Exception('Bonus:invite:minclass');
        }
        $this->db->prepared_query('
            UPDATE user_bonus ub
            INNER JOIN users_main um ON (um.ID = ub.user_id) SET
                ub.points = ub.points - ?,
                um.Invites = um.Invites + 1
            WHERE ub.points >= ?
                AND ub.user_id = ?
            ', $price, $price, $userId
        );
        if ($this->db->affected_rows() != 2) {
            throw new \Exception('Bonus:invite:nofunds');
        }
        $this->addPurchaseHistory($item['ID'], $userId, $price);
        $this->flushUserCache($userId);
        return true;
    }

    public function purchaseTitle($userId, $label, $title) {
        $item  = $this->items[$label];
        $title = $label === 'title-bb-y' ? \Text::full_format($title) : \Text::strip_bbcode($title);
        if (mb_strlen($title) > 1024) {
            throw new \Exception('Bonus:title:too-long');
        }
        $price = $this->getEffectivePrice($label, $userId);

        /* if the price is 0, nothing changes so avoid hitting the db */
        if ($price > 0) {
            if (!$this->removePoints($userId, $price)) {
                throw new \Exception('Bonus:title:nofunds');
            }
        }
        if (!\Users::setCustomTitle($userId, $title)) {
            throw new \Exception('Bonus:title:set');
            return false;
        }
        $this->addPurchaseHistory($item['ID'], $userId, $price);
        $this->flushUserCache($userId);
        return true;
    }

    public function purchaseCollage($userId, $label) {
        $item  = $this->items[$label];
        $price = $this->getEffectivePrice($label, $userId);

        if (!\Users::canPurchaseInvite($userId, $item['MinClass'])) {
            throw new \Exception('Bonus:invite:minclass');
        }
        $this->db->prepared_query('
            UPDATE user_bonus ub
            INNER JOIN users_info ui ON (ui.UserID = ub.user_id) SET
                ub.points = ub.points - ?,
                ui.collages = ui.collages + 1
            WHERE ub.points >= ?
                AND ub.user_id = ?
            ', $price, $price, $userId
        );
        if ($this->db->affected_rows() != 2) {
            throw new \Exception('Bonus:collage:nofunds');
        }
        $this->addPurchaseHistory($item['ID'], $userId, $price);
        $this->flushUserCache($userId);
        return true;
    }

    public function purchaseToken($userId, $label) {
        if (!array_key_exists($label, $this->items)) {
            throw new \Exception('Bonus:selfToken:badlabel');
        }
        $item   = $this->items[$label];
        $amount = $item['Amount'];
        $price  = $item['Price'];
        $this->db->prepared_query('
            UPDATE user_bonus ub
            INNER JOIN user_flt uf USING (user_id) SET
                ub.points = ub.points - ?,
                uf.tokens = uf.tokens + ?
            WHERE ub.user_id = ?
                AND ub.points >= ?
            ', $price, $amount, $userId, $price
        );
        if ($this->db->affected_rows() != 2) {
            throw new \Exception('Bonus:selfToken:funds');
        }
        $this->addPurchaseHistory($item['ID'], $userId, $price);
        $this->flushUserCache($userId);
        return (int)$amount;
    }

    public function purchaseTokenOther($fromID, $toID, $label) {
        if ($fromID === $toID) {
            throw new \Exception('Bonus:otherToken:self');
        }
        if (!array_key_exists($label, $this->items)) {
            throw new \Exception('Bonus:otherToken:badlabel');
        }
        $item  = $this->items[$label];
        $amount = $item['Amount'];
        $price  = $item['Price'];

        /* Take the bonus points from the giver and give tokens
         * to the receiver, unless the latter have asked to
         * refuse receiving tokens.
         */
        $this->db->prepared_query("
            UPDATE user_bonus ub
            INNER JOIN users_main self ON (self.ID = ub.user_id),
                users_main other
                INNER JOIN user_flt other_uf ON (other_uf.user_id = other.ID)
                LEFT JOIN user_has_attr noFL ON (noFL.UserID = other.ID AND noFL.UserAttrId
                    = (SELECT ua.ID FROM user_attr ua WHERE ua.Name = 'no-fl-gifts')
                )
            SET
                ub.points = ub.points - ?,
                other_uf.tokens = other_uf.tokens + ?
            WHERE noFL.UserID IS NULL
                AND other.Enabled = '1'
                AND other.ID = ?
                AND self.ID = ?
                AND ub.points >= ?
            ", $price, $amount, $toID, $fromID, $price
        );
        if ($this->db->affected_rows() != 2) {
            throw new \Exception('Bonus:otherToken:no-gift-funds');
        }
        $this->addPurchaseHistory($item['ID'], $fromID, $price, $toID);
        $this->cache->deleteMulti([
            'user_info_heavy_' . $fromID,
            'user_info_heavy_' . $toID,
            'user_stats_' . $fromID,
            'user_stats_' . $toID,
        ]);
        $this->sendPmToOther($fromID, $toID, $amount);

        return (int)$amount;
    }

    public function sendPmToOther($fromID, $toID, $amount) {
        \Misc::send_pm($toID, 0,
            "Here " . ($amount == 1 ? 'is' : 'are') . ' ' . article($amount) . " freeleech token" . plural($amount) . "!",
            \G::$Twig->render('bonus/token-other.twig', [
                'TO'       => \Users::user_info($toID)['Username'],
                'FROM'     => \Users::user_info($fromID)['Username'],
                'AMOUNT'   => $amount,
                'PLURAL'   => plural($amount),
                'SITE_URL' => SITE_URL,
                'WIKI_ID'  => 57,
            ])
        );
    }

    private function addPurchaseHistory($item_id, $userId, $price, $other_userId = null) {
        $this->cache->delete_value(self::CACHE_SUMMARY . $userId);
        $this->cache->delete_value(self::CACHE_HISTORY . $userId . ".1");
        $this->db->prepared_query(
            'INSERT INTO bonus_history (ItemID, UserID, price, OtherUserID) VALUES (?, ?, ?, ?)',
            $item_id, $userId, $price, $other_userId
        );
        return $this->db->affected_rows();
    }

    public function setPoints($userId, $points) {
        $this->db->prepared_query('UPDATE user_bonus SET points = ? WHERE user_id = ?', $points, $userId);
        $this->flushUserCache($userId);
    }

    public function addPoints($userId, $points) {
        $this->db->prepared_query('UPDATE user_bonus SET points = points + ? WHERE user_id = ?', $points, $userId);
        $this->flushUserCache($userId);
    }

    public function addMultiPoints(int $points, array $ids = []): int {
        if ($ids) {
            $this->db->prepared_query("
                UPDATE user_bonus SET
                    points = points + ?
                WHERE user_id in (" . placeholders($ids) . ")
                ", $points, ...$ids
            );
            $this->cache->deleteMulti(array_map(function ($k) { return "user_stats_$k"; }, $ids));
        }
        return count($ids);
    }

    public function addGlobalPoints(int $points): int {
        $this->db->prepared_query("
            SELECT um.ID
            FROM users_main um
            INNER JOIN users_info ui ON (ui.UserID = um.ID)
            LEFT JOIN user_has_attr AS uhafl ON (uhafl.UserID = um.ID)
            LEFT JOIN user_attr as uafl ON (uafl.ID = uhafl.UserAttrID AND uafl.Name = 'no-fl-gifts')
            WHERE ui.DisablePoints = '0'
                AND um.Enabled = '1'
                AND uhafl.UserID IS NULL
        ");
        return $this->addMultiPoints($points, $this->db->collect('ID', false));
    }

    public function addActivePoints(int $points, string $since): int {
        $this->db->prepared_query("
            SELECT um.ID
            FROM users_main um
            INNER JOIN users_info ui ON (ui.UserID = um.ID)
            INNER JOIN user_last_access ula ON (ula.user_id = um.ID)
            LEFT JOIN user_has_attr AS uhafl ON (uhafl.UserID = um.ID)
            LEFT JOIN user_attr as uafl ON (uafl.ID = uhafl.UserAttrID AND uafl.Name = 'no-fl-gifts')
            WHERE ui.DisablePoints = '0'
                AND um.Enabled = '1'
                AND uhafl.UserID IS NULL
                AND ula.last_access >= ?
            ", $since
        );
        return $this->addMultiPoints($points, $this->db->collect('ID', false));
    }

    public function addUploadPoints(int $points, string $since): int {
        $this->db->prepared_query($sql = "
            SELECT DISTINCT um.ID
            FROM users_main um
            INNER JOIN users_info ui ON (ui.UserID = um.ID)
            INNER JOIN torrents t ON (t.UserID = um.ID)
            LEFT JOIN user_has_attr AS uhafl ON (uhafl.UserID = um.ID)
            LEFT JOIN user_attr as uafl ON (uafl.ID = uhafl.UserAttrID AND uafl.Name = 'no-fl-gifts')
            WHERE ui.DisablePoints = '0'
                AND um.Enabled = '1'
                AND uhafl.UserID IS NULL
                AND t.Time >= ?
            ", $since
        );
        return $this->addMultiPoints($points, $this->db->collect('ID', false));
    }

    public function addSeedPoints(int $points): int {
        $this->db->prepared_query("
            SELECT DISTINCT um.ID
            FROM users_main um
            INNER JOIN users_info ui ON (ui.UserID = um.ID)
            INNER JOIN xbt_files_users xfu ON (xfu.uid = um.ID)
            LEFT JOIN user_has_attr AS uhafl ON (uhafl.UserID = um.ID)
            LEFT JOIN user_attr as uafl ON (uafl.ID = uhafl.UserAttrID AND uafl.Name = 'no-fl-gifts')
            WHERE ui.DisablePoints = '0'
                AND um.Enabled = '1'
                AND uhafl.UserID IS NULL
                AND xfu.active = 1 and xfu.remaining = 0 and xfu.connectable = 1 and timespent > 0
        ");
        return $this->addMultiPoints($points, $this->db->collect('ID', false));
    }

    public function removePointsForUpload($userId, array $torrentDetails) {
        list($Format, $Media, $Encoding, $HasLogDB, $LogScore, $LogChecksum) = $torrentDetails;
        $value = $this->getTorrentValue($Format, $Media, $Encoding, $HasLogDB, $LogScore, $LogChecksum);
        return $this->removePoints($userId, $value, true);
    }

    public function removePoints($userId, $points, $force = false) {
        if ($force) {
        // allow points to go negative
            $this->db->prepared_query('
                UPDATE user_bonus SET points = points - ? WHERE user_id = ?
                ', $points, $userId
            );
        } else {
            // Fail if points would go negative
            $this->db->prepared_query('
                UPDATE user_bonus SET points = points - ? WHERE points >= ?  AND user_id = ?
                ', $points, $points, $userId
            );
            if ($this->db->affected_rows() != 1) {
                return false;
            }
        }
        $this->flushUserCache($userId);
        return true;
    }

    public function userHourlyRate(int $userId): float {
        return $this->db->scalar("
            SELECT coalesce(sum(bonus_accrual(t.Size, xfh.seedtime, tls.Seeders)), 0) as Rate
            FROM (SELECT DISTINCT uid,fid FROM xbt_files_users WHERE active=1 AND remaining=0 AND mtime > unix_timestamp(NOW() - INTERVAL 1 HOUR) AND uid = ?) AS xfu
            INNER JOIN xbt_files_history AS xfh USING (uid, fid)
            INNER JOIN torrents AS t ON (t.ID = xfu.fid)
            INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
            WHERE xfu.uid = ?
                AND NOT (t.Format = 'MP3' AND t.Encoding = 'V2 (VBR)')
            ", $userId, $userId
        );
    }

    public function userTotals($userId) {
        [$total, $size, $hourly, $daily, $weekly, $monthly, $yearly, $ppGB] = $this->db->row("
            SELECT
                count(xfu.uid) as TotalTorrents,
                sum(t.Size) as TotalSize,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime,                           tls.Seeders)), 0)                           AS HourlyPoints,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime + (24 * 1),                tls.Seeders)), 0) * (24 * 1)                AS DailyPoints,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime + (24 * 7),                tls.Seeders)), 0) * (24 * 7)                AS WeeklyPoints,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004/12), tls.Seeders)), 0) * (24 * 365.256363004/12) AS MonthlyPoints,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004),    tls.Seeders)), 0) * (24 * 365.256363004)    AS YearlyPoints,
                coalesce(sum(bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004),    tls.Seeders)), 0) * (24 * 365.256363004)
                    / (coalesce(sum(t.Size), 1) / (1024*1024*1024)) AS PointsPerGB
            FROM (
                SELECT DISTINCT uid,fid FROM xbt_files_users WHERE active=1 AND remaining=0 AND mtime > unix_timestamp(NOW() - INTERVAL 1 HOUR) AND uid = ?
            ) AS xfu
            INNER JOIN xbt_files_history AS xfh USING (uid, fid)
            INNER JOIN torrents AS t ON (t.ID = xfu.fid)
            INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
            WHERE
                xfu.uid = ?
                AND NOT (t.Format = 'MP3' AND t.Encoding = 'V2 (VBR)')
            ", $userId, $userId
        );
        return [(int)$total, (float)$size, (float)$hourly, (float)$daily, (float)$weekly, (float)$monthly, (float)$yearly, (float)$ppGB];
    }

    public function userDetails($userId, $orderBy, $orderWay, $limit, $offset) {
        $this->db->prepared_query("
            SELECT
                t.ID,
                t.GroupID,
                t.Size,
                t.Format,
                t.Encoding,
                t.HasLog,
                t.HasLogDB,
                t.HasCue,
                t.LogScore,
                t.LogChecksum,
                t.Media,
                t.Scene,
                t.RemasterYear,
                t.RemasterTitle,
                GREATEST(tls.Seeders, 1) AS Seeders,
                xfh.seedtime AS Seedtime,
                bonus_accrual(t.Size, xfh.seedtime,                           tls.Seeders)                           AS HourlyPoints,
                bonus_accrual(t.Size, xfh.seedtime + (24 * 1),                tls.Seeders) * (24 * 1)                AS DailyPoints,
                bonus_accrual(t.Size, xfh.seedtime + (24 * 7),                tls.Seeders) * (24 * 7)                AS WeeklyPoints,
                bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004/12), tls.Seeders) * (24 * 365.256363004/12) AS MonthlyPoints,
                bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004),    tls.Seeders) * (24 * 365.256363004)    AS YearlyPoints,
                bonus_accrual(t.Size, xfh.seedtime + (24 * 365.256363004),    tls.Seeders) * (24 * 365.256363004)
                    / (t.Size / (1024*1024*1024)) AS PointsPerGB
            FROM (
                SELECT DISTINCT uid,fid FROM xbt_files_users WHERE active=1 AND remaining=0 AND mtime > unix_timestamp(NOW() - INTERVAL 1 HOUR) AND uid = ?
            ) AS xfu
            INNER JOIN xbt_files_history AS xfh USING (uid, fid)
            INNER JOIN torrents AS t ON (t.ID = xfu.fid)
            INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
            WHERE
                xfu.uid = ?
                AND NOT (t.Format = 'MP3' AND t.Encoding = 'V2 (VBR)')
            ORDER BY $orderBy $orderWay
            LIMIT ?
            OFFSET ?
            ", $userId, $userId, $limit, $offset
        );
        return [$this->db->collect('GroupID'), $this->db->to_array('ID', MYSQLI_ASSOC)];
    }

    public function givePoints(\Gazelle\Schedule\Task $task = null) {
        //------------------------ Update Bonus Points -------------------------//
        // calcuation:
        // Size * (0.0754 + (0.1207 * ln(1 + seedtime)/ (seeders ^ 0.55)))
        // Size (convert from bytes to GB) is in torrents
        // Seedtime (convert from hours to days) is in xbt_snatched
        // Seeders is in torrents

        // precalculate the users we update this run
        if ($task) {
            $task->debug('begin');
        } else {
            echo "begin\n";
        }
        $this->db->prepared_query("
            CREATE TEMPORARY TABLE xbt_unique (
                uid int(11) NOT NULL,
                fid int(11) NOT NULL,
                PRIMARY KEY (uid, fid)
            )
            SELECT DISTINCT uid, fid
            FROM xbt_files_users xfu
            INNER JOIN users_main AS um ON (um.ID = xfu.uid)
            INNER JOIN users_info AS ui ON (ui.UserID = xfu.uid)
            INNER JOIN torrents   AS t  ON (t.ID = xfu.fid)
            WHERE xfu.active = 1
                AND xfu.remaining = 0
                AND xfu.mtime > unix_timestamp(now() - INTERVAL 1 HOUR)
                AND um.Enabled = '1'
                AND ui.DisablePoints = '0'
                AND NOT (t.Format = 'MP3' AND t.Encoding = 'V2 (VBR)')
        ");
        if ($task) {
            $task->debug('xbt_unique constructed');
        } else {
            echo "xbt_unique constructed\n";
        }

        $userId = 1;
        $chunk = 150;
        $processed = 0;
        $more = true;
        while ($more) {
            /* update a block of users at a time, to minimize locking contention */
            $this->db->prepared_query("
                INSERT INTO user_bonus
                SELECT
                    xfu.uid AS ID,
                    sum(bonus_accrual(t.Size, xfh.seedtime, tls.Seeders)) as new
                FROM xbt_unique xfu
                INNER JOIN xbt_files_history AS xfh USING (uid, fid)
                INNER JOIN torrents AS t ON (t.ID = xfu.fid)
                INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
                WHERE xfu.uid BETWEEN ? AND ?
                    AND NOT (t.Format = 'MP3' AND t.Encoding = 'V2 (VBR)')
                GROUP BY
                    xfu.uid
                ON DUPLICATE KEY UPDATE points = points + VALUES(points)
                ", $userId, $userId + $chunk - 1
            );
            $processed += $this->db->affected_rows();

            /* flush their stats */
            $this->db->prepared_query("
                SELECT concat('user_stats_', xfu.uid) as ck
                FROM xbt_unique xfu
                WHERE xfu.uid BETWEEN ? AND ?
                ", $userId, $userId + $chunk - 1
            );
            if ($this->db->has_results()) {
                $this->cache->deleteMulti($this->db->collect('ck', false));
            }
            if ($task) {
                $task->debug('chunk done', $userId);
            } else {
                echo "chunk done $userId\n";
            }

            /* see if there are some more users to process */
            $userId += $chunk;
            $more = $this->db->scalar("
                SELECT 1
                FROM xbt_unique
                WHERE uid >= ?
                ", $userId
            );
        }
        return $processed;
    }
}
