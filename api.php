<?php
/*
 * YTInsight — api.php
 * Backend: Firebase Admin + YouTube Data API v3 + YouTube Analytics API
 * OAuth Client ID: 808342592817-8uj7cfkl9ap7o8fi5hrecc363mcd7n12.apps.googleusercontent.com
 *
 * SETUP (upload this file alongside index.html):
 *   1. Set your Firebase service-account JSON path below ($FIREBASE_SA_PATH)
 *   2. Set your YouTube Data API server key below ($YT_SERVER_KEY)
 *   3. chmod 600 your service-account JSON
 *   4. Make sure PHP cURL is enabled (php.ini: extension=curl)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ═══════════════════════════════════════════════════════════════════════════
   CONFIG  — edit these values
   ═════════════════════════════════════════════════════════════════════════ */
define('FIREBASE_PROJECT_ID', 'spaintoearn');
define('FIREBASE_DB_URL',     'https://spaintoearn-default-rtdb.firebaseio.com');
define('FIREBASE_WEB_API_KEY','AIzaSyCdrPLotWyFJXsRcBJrga91LbiAXGicbpY');   // same as in HTML
define('GOOGLE_CLIENT_ID',    '808342592817-8uj7cfkl9ap7o8fi5hrecc363mcd7n12.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET','');   // <-- paste your client secret here (from Google Cloud Console)

// YouTube Data API server key (create one in Google Cloud Console → Credentials → API key)
define('YT_SERVER_KEY', '');  // <-- paste your YouTube server API key here

// Firebase service account JSON path (download from Firebase Console → Project Settings → Service Accounts)
define('FIREBASE_SA_PATH', __DIR__ . '/firebase-service-account.json');

/* ═══════════════════════════════════════════════════════════════════════════
   ROUTER
   ═════════════════════════════════════════════════════════════════════════ */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    if (empty($action)) $action = $body['action'] ?? '';
}

try {
    switch ($action) {
        case 'sync_user':              syncUser($body);                break;
        case 'submit_lead':            submitLead($body);             break;
        case 'connect_youtube_channel':connectYouTubeChannel($body); break;
        case 'disconnect_youtube_channel': disconnectChannel($body); break;
        case 'upload_video':           uploadVideo($body);            break;
        case 'get_video_analytics':    getVideoAnalytics($body);      break;
        case 'get_channel_analytics':  getChannelAnalytics($body);    break;
        default:
            jsonOut(['success'=>true,'message'=>'YTInsight API v2.0 ready']);
    }
} catch (Throwable $e) {
    jsonErr($e->getMessage(), 500);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: sync_user  — create/update user node in Firebase RTDB
   ═════════════════════════════════════════════════════════════════════════ */
function syncUser(array $b): void {
    $uid   = trim($b['uid']   ?? '');
    $email = trim($b['email'] ?? '');
    if (!$uid) { jsonOut(['success'=>true]); return; }

    $data = [
        'uid'       => $uid,
        'email'     => $email,
        'name'      => $b['name'] ?? '',
        'lastLogin' => date('c'),
    ];
    fbUpdate("users/$uid/profile", $data);
    jsonOut(['success'=>true]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: submit_lead
   ═════════════════════════════════════════════════════════════════════════ */
function submitLead(array $b): void {
    $data = [
        'name'     => $b['name']     ?? '',
        'email'    => $b['email']    ?? '',
        'whatsapp' => $b['whatsapp'] ?? '',
        'message'  => $b['message']  ?? '',
        'at'       => date('c'),
    ];
    $key = 'lead_' . time() . '_' . substr(md5($data['email']),0,6);
    fbSet("leads/$key", $data);
    jsonOut(['success'=>true]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: connect_youtube_channel
   ─── Flow ───────────────────────────────────────────────────────────────
   Frontend sends: { uid, accessToken }
   We:
     1. Verify token via Google tokeninfo
     2. Call YouTube Data API channels.list (mine=true) using access token
     3. Call YouTube Analytics API for views/watchtime (if scope granted)
     4. Save everything to Firebase RTDB users/{uid}/myChannel
     5. Return channel object to frontend
   ═════════════════════════════════════════════════════════════════════════ */
function connectYouTubeChannel(array $b): void {
    $uid   = trim($b['uid']         ?? '');
    $token = trim($b['accessToken'] ?? '');
    if (!$uid || !$token) jsonErr('Missing uid or accessToken', 400);

    // 1. Verify the token
    $info = curlGet("https://oauth2.googleapis.com/tokeninfo?access_token=" . urlencode($token));
    if (!empty($info['error'])) jsonErr('Invalid Google access token: ' . ($info['error_description'] ?? $info['error']), 401);

    // 2. YouTube Data API — channel info (mine=true uses the access token)
    $chRes = curlGetAuth(
        'https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics,brandingSettings,topicDetails&mine=true',
        $token
    );
    if (empty($chRes['items'])) jsonErr('No YouTube channel found for this Google account. Make sure the account has a YouTube channel.', 404);

    $ch  = $chRes['items'][0];
    $sn  = $ch['snippet']    ?? [];
    $st  = $ch['statistics'] ?? [];
    $td  = $ch['topicDetails']['topicCategories'] ?? [];
    $cat = !empty($td[0]) ? explode('/', $td[0]) : [];
    $cat = end($cat) ?: 'General';
    $cat = str_replace('_', ' ', $cat);

    $channelId = $ch['id'];

    // 3. Fetch recent videos via YouTube Data API search (uses access token or server key)
    $ytKey      = defined('YT_SERVER_KEY') && YT_SERVER_KEY ? YT_SERVER_KEY : null;
    $videosData = [];
    try {
        $searchUrl = 'https://www.googleapis.com/youtube/v3/search?part=snippet'
                   . '&channelId=' . urlencode($channelId)
                   . '&maxResults=20&order=date&type=video';
        $srRes = $ytKey
            ? curlGet($searchUrl . '&key=' . urlencode($ytKey))
            : curlGetAuth($searchUrl, $token);

        $videoIds = array_column(array_column($srRes['items'] ?? [], 'id'), 'videoId');
        $videoIds = array_filter($videoIds);

        if ($videoIds) {
            $vidUrl = 'https://www.googleapis.com/youtube/v3/videos?part=snippet,statistics,contentDetails'
                    . '&id=' . urlencode(implode(',', $videoIds));
            $vRes = $ytKey
                ? curlGet($vidUrl . '&key=' . urlencode($ytKey))
                : curlGetAuth($vidUrl, $token);

            foreach (($vRes['items'] ?? []) as $v) {
                $vs = $v['statistics'] ?? [];
                $dur = $v['contentDetails']['duration'] ?? '';
                preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $dur, $dm);
                $secs = intval($dm[1]??0)*3600 + intval($dm[2]??0)*60 + intval($dm[3]??0);
                $videosData[] = [
                    'videoId'      => $v['id'],
                    'title'        => $v['snippet']['title']        ?? '',
                    'thumbnail'    => $v['snippet']['thumbnails']['medium']['url'] ?? '',
                    'publishedAt'  => $v['snippet']['publishedAt']  ?? '',
                    'views'        => intval($vs['viewCount']        ?? 0),
                    'likes'        => intval($vs['likeCount']        ?? 0),
                    'comments'     => intval($vs['commentCount']     ?? 0),
                    'durationSecs' => $secs,
                    'isShort'      => $secs > 0 && $secs <= 70,
                    'subsGained'   => 0,   // Analytics API needed for this
                    'watchTimeMinutes' => 0,
                ];
            }
        }
    } catch (Throwable $e) { /* videos optional, continue */ }

    // 4. YouTube Analytics API — channel-level metrics (last 30 days)
    $viewsToday     = 0;
    $viewsYesterday = 0;
    $viewsThisMonth = 0;
    $watchTimeTotal = 0;
    $totalLikes     = 0;
    try {
        $endDate   = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $anaUrl    = 'https://youtubeanalytics.googleapis.com/v2/reports'
                   . '?ids=channel==' . urlencode($channelId)
                   . '&startDate=' . $startDate
                   . '&endDate='   . $endDate
                   . '&metrics=views,estimatedMinutesWatched,likes'
                   . '&dimensions=day'
                   . '&sort=day';
        $anaRes = curlGetAuth($anaUrl, $token);
        if (!empty($anaRes['rows'])) {
            $rows = $anaRes['rows'];
            $viewsThisMonth = array_sum(array_column($rows, 0));
            $watchTimeTotal = array_sum(array_column($rows, 1));
            $totalLikes     = array_sum(array_column($rows, 2));
            $today     = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            foreach ($rows as $row) {
                if (($row[0] ?? '') === $today)     $viewsToday     = intval($row[1] ?? 0);
                if (($row[0] ?? '') === $yesterday) $viewsYesterday = intval($row[1] ?? 0);
            }
        }
    } catch (Throwable $e) { /* analytics optional */ }

    // Calculate total likes from video list if analytics didn't return
    if (!$totalLikes && $videosData) {
        $totalLikes = array_sum(array_column($videosData, 'likes'));
    }

    // 5. Build channel object and save to Firebase
    $channelObj = [
        'channelId'        => $channelId,
        'title'            => $sn['title']       ?? '',
        'handle'           => ltrim($sn['customUrl'] ?? '', '@'),
        'description'      => $sn['description'] ?? '',
        'thumbnail'        => $sn['thumbnails']['medium']['url'] ?? $sn['thumbnails']['default']['url'] ?? '',
        'country'          => $sn['country']     ?? ($ch['brandingSettings']['channel']['country'] ?? ''),
        'publishedAt'      => $sn['publishedAt'] ?? '',
        'category'         => $cat,
        'subscribers'      => intval($st['subscriberCount'] ?? 0),
        'totalViews'       => intval($st['viewCount']       ?? 0),
        'videoCount'       => intval($st['videoCount']      ?? 0),
        'totalLikes'       => $totalLikes,
        'viewsToday'       => $viewsToday,
        'viewsYesterday'   => $viewsYesterday,
        'viewsThisMonth'   => $viewsThisMonth,
        'watchTimeMinutes' => $watchTimeTotal,
        'videos'           => $videosData,
        'lastSynced'       => date('c'),
        'accessToken'      => $token,  // stored for future API calls (expires ~1h; refresh flow TODO)
    ];

    fbSet("users/$uid/myChannel", $channelObj);
    jsonOut(['success'=>true, 'channel'=>$channelObj]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: disconnect_youtube_channel
   ═════════════════════════════════════════════════════════════════════════ */
function disconnectChannel(array $b): void {
    $uid = trim($b['uid'] ?? '');
    if ($uid) fbDelete("users/$uid/myChannel");
    jsonOut(['success'=>true]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: upload_video
   Body: { uid, accessToken, title, description, tags[], hashtags[],
           privacyStatus (public|private|unlisted), publishAt (ISO or null),
           videoBase64 (optional, for small files) }
   ═════════════════════════════════════════════════════════════════════════ */
function uploadVideo(array $b): void {
    $uid     = trim($b['uid']          ?? '');
    $token   = trim($b['accessToken']  ?? '');
    $title   = trim($b['title']        ?? '');
    $desc    = trim($b['description']  ?? '');
    $tags    = $b['tags']    ?? [];
    $privacy = $b['privacyStatus'] ?? 'private';
    $pubAt   = $b['publishAt']     ?? null;   // ISO string for scheduled

    if (!$uid || !$token)  jsonErr('Missing uid or accessToken', 400);
    if (!$title)           jsonErr('Video title is required', 400);

    // Build video resource
    $resource = [
        'snippet' => [
            'title'       => $title,
            'description' => $desc,
            'tags'        => array_values(array_filter($tags)),
            'categoryId'  => '22',  // People & Blogs; user can change in YouTube Studio
        ],
        'status' => [
            'privacyStatus'         => in_array($privacy, ['public','private','unlisted']) ? $privacy : 'private',
            'selfDeclaredMadeForKids' => false,
        ],
    ];
    if ($pubAt && $privacy === 'private') {
        // Scheduled upload: set publishAt + unlisted trick
        $resource['status']['publishAt']    = $pubAt;
        $resource['status']['privacyStatus'] = 'private';
    }

    // YouTube Data API — videos.insert  (resumable upload for actual file)
    // Here we do metadata-only insert; the client streams the file separately.
    // For simplicity we use the simple upload with base64 data if provided.
    $videoBase64 = $b['videoBase64'] ?? null;

    if ($videoBase64) {
        // Decode and upload (works for files < 256MB; YouTube allows up to 256GB via resumable)
        $videoBytes = base64_decode($videoBase64);
        if (!$videoBytes) jsonErr('Invalid video data', 400);

        // Step 1: initiate resumable upload
        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos'
                 . '?uploadType=resumable&part=snippet,status';
        $initRes = curlPostAuth($initUrl, $token, json_encode($resource), [
            'Content-Type: application/json',
            'X-Upload-Content-Type: video/*',
            'X-Upload-Content-Length: ' . strlen($videoBytes),
        ], true /* return headers */);

        $uploadUrl = $initRes['Location'] ?? '';
        if (!$uploadUrl) jsonErr('Could not initiate YouTube upload. Check token permissions.', 502);

        // Step 2: PUT the video bytes
        $uploadResult = curlPutRaw($uploadUrl, $videoBytes, 'video/*');
        if (empty($uploadResult['id'])) jsonErr('Upload failed: ' . json_encode($uploadResult), 502);

        $videoId = $uploadResult['id'];
    } else {
        // No video bytes — just insert metadata and return a resumable upload URL
        // Frontend will use this URL to upload the file directly to YouTube
        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos'
                 . '?uploadType=resumable&part=snippet,status';
        $initRes = curlPostAuth($initUrl, $token, json_encode($resource), [
            'Content-Type: application/json',
            'X-Upload-Content-Type: video/*',
        ], true);

        $uploadUrl = $initRes['Location'] ?? '';
        if (!$uploadUrl) jsonErr('Could not get upload URL. Check YouTube permissions.', 502);

        // Save pending upload to Firebase
        $pending = [
            'title'        => $title,
            'description'  => $desc,
            'tags'         => $tags,
            'privacyStatus'=> $privacy,
            'publishAt'    => $pubAt,
            'uploadUrl'    => $uploadUrl,
            'status'       => 'pending_upload',
            'createdAt'    => date('c'),
        ];
        $key = 'upload_' . time();
        fbSet("users/$uid/uploads/$key", $pending);

        jsonOut(['success'=>true, 'uploadUrl'=>$uploadUrl, 'uploadKey'=>$key,
                 'message'=>'Use the uploadUrl to PUT your video file directly to YouTube.']);
        return;
    }

    // Save completed upload record
    $record = [
        'videoId'      => $videoId,
        'title'        => $title,
        'description'  => $desc,
        'tags'         => $tags,
        'privacyStatus'=> $privacy,
        'publishAt'    => $pubAt,
        'status'       => 'uploaded',
        'uploadedAt'   => date('c'),
    ];
    fbSet("users/$uid/uploads/$videoId", $record);
    jsonOut(['success'=>true, 'videoId'=>$videoId,
             'videoUrl'=> "https://www.youtube.com/watch?v=$videoId"]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: get_video_analytics  — per-video stats for prediction
   Body: { uid, accessToken, videoId, channelId }
   ═════════════════════════════════════════════════════════════════════════ */
function getVideoAnalytics(array $b): void {
    $token     = trim($b['accessToken'] ?? '');
    $videoId   = trim($b['videoId']     ?? '');
    $channelId = trim($b['channelId']   ?? '');
    if (!$token || !$videoId) jsonErr('Missing accessToken or videoId', 400);

    $endDate   = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-28 days'));

    try {
        $anaUrl = 'https://youtubeanalytics.googleapis.com/v2/reports'
                . '?ids=channel==' . urlencode($channelId ?: 'mine')
                . '&startDate=' . $startDate
                . '&endDate='   . $endDate
                . '&metrics=views,estimatedMinutesWatched,likes,subscribersGained,averageViewDuration'
                . '&dimensions=day'
                . '&filters=video==' . urlencode($videoId)
                . '&sort=day';
        $rows = (curlGetAuth($anaUrl, $token))['rows'] ?? [];

        $totalViews   = array_sum(array_column($rows, 0));
        $watchTime    = array_sum(array_column($rows, 1));
        $likes        = array_sum(array_column($rows, 2));
        $subsGained   = array_sum(array_column($rows, 3));
        $avgViewDur   = $rows ? array_sum(array_column($rows, 4)) / count($rows) : 0;

        jsonOut([
            'success'       => true,
            'videoId'       => $videoId,
            'views'         => $totalViews,
            'watchTimeMin'  => $watchTime,
            'likes'         => $likes,
            'subsGained'    => $subsGained,
            'avgViewDurSec' => round($avgViewDur),
            'dailyRows'     => $rows,
        ]);
    } catch (Throwable $e) {
        jsonErr('Analytics error: ' . $e->getMessage(), 502);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   ACTION: get_channel_analytics
   ═════════════════════════════════════════════════════════════════════════ */
function getChannelAnalytics(array $b): void {
    $token     = trim($b['accessToken'] ?? '');
    $channelId = trim($b['channelId']   ?? '');
    if (!$token) jsonErr('Missing accessToken', 400);

    $endDate   = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-30 days'));

    $anaUrl = 'https://youtubeanalytics.googleapis.com/v2/reports'
            . '?ids=channel==' . urlencode($channelId ?: 'mine')
            . '&startDate=' . $startDate
            . '&endDate='   . $endDate
            . '&metrics=views,estimatedMinutesWatched,likes,subscribersGained'
            . '&dimensions=day&sort=day';
    $res = curlGetAuth($anaUrl, $token);
    jsonOut(['success'=>true, 'rows'=>$res['rows'] ?? [], 'columnHeaders'=>$res['columnHeaders'] ?? []]);
}

/* ═══════════════════════════════════════════════════════════════════════════
   FIREBASE RTDB HELPERS  (REST API — no SDK needed)
   ═════════════════════════════════════════════════════════════════════════ */
function fbToken(): string {
    // Use a cached token stored in /tmp for up to 55 minutes
    $cache = sys_get_temp_dir() . '/ytinsight_fb_token.json';
    if (file_exists($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['token']) && !empty($c['exp']) && $c['exp'] > time() + 60) {
            return $c['token'];
        }
    }

    // Generate JWT for service account
    if (!file_exists(FIREBASE_SA_PATH)) {
        // Fallback: use the public web API key for unauthenticated writes (less secure)
        return '__WEB_KEY__';
    }
    $sa    = json_decode(file_get_contents(FIREBASE_SA_PATH), true);
    $now   = time();
    $claim = [
        'iss'   => $sa['client_email'],
        'sub'   => $sa['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
        'scope' => 'https://www.googleapis.com/auth/firebase https://www.googleapis.com/auth/cloud-platform',
    ];
    $jwt   = jwtSign($claim, $sa['private_key']);
    $res   = curlPost('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $tok = $res['access_token'] ?? '';
    file_put_contents($cache, json_encode(['token'=>$tok,'exp'=>$now+3600]));
    return $tok;
}

function fbSet(string $path, array $data): void {
    $tok = fbToken();
    $url = FIREBASE_DB_URL . '/' . ltrim($path,'/') . '.json'
         . ($tok === '__WEB_KEY__' ? '?auth=' . FIREBASE_WEB_API_KEY : '');
    $headers = ['Content-Type: application/json'];
    if ($tok !== '__WEB_KEY__') $headers[] = 'Authorization: Bearer ' . $tok;
    curlRequest('PUT', $url, json_encode($data), $headers);
}

function fbUpdate(string $path, array $data): void {
    $tok = fbToken();
    $url = FIREBASE_DB_URL . '/' . ltrim($path,'/') . '.json'
         . ($tok === '__WEB_KEY__' ? '?auth=' . FIREBASE_WEB_API_KEY : '');
    $headers = ['Content-Type: application/json'];
    if ($tok !== '__WEB_KEY__') $headers[] = 'Authorization: Bearer ' . $tok;
    curlRequest('PATCH', $url, json_encode($data), $headers);
}

function fbDelete(string $path): void {
    $tok = fbToken();
    $url = FIREBASE_DB_URL . '/' . ltrim($path,'/') . '.json'
         . ($tok === '__WEB_KEY__' ? '?auth=' . FIREBASE_WEB_API_KEY : '');
    $headers = [];
    if ($tok !== '__WEB_KEY__') $headers[] = 'Authorization: Bearer ' . $tok;
    curlRequest('DELETE', $url, null, $headers);
}

/* ═══════════════════════════════════════════════════════════════════════════
   JWT HELPER (RS256)
   ═════════════════════════════════════════════════════════════════════════ */
function jwtSign(array $payload, string $privateKey): string {
    $header  = base64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $payload = base64url(json_encode($payload));
    $sig = '';
    openssl_sign("$header.$payload", $sig, $privateKey, OPENSSL_ALGO_SHA256);
    return "$header.$payload." . base64url($sig);
}
function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/* ═══════════════════════════════════════════════════════════════════════════
   CURL HELPERS
   ═════════════════════════════════════════════════════════════════════════ */
function curlGet(string $url): array {
    return curlRequest('GET', $url);
}

function curlGetAuth(string $url, string $token): array {
    return curlRequest('GET', $url, null, ['Authorization: Bearer ' . $token]);
}

function curlPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?? [];
}

function curlPostAuth(string $url, string $token, string $body, array $extraHeaders = [], bool $returnHeaders = false) {
    $headers = array_merge(['Authorization: Bearer ' . $token], $extraHeaders);
    if ($returnHeaders) {
        // Return response headers (for resumable upload Location)
        $headerStr = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headerStr = substr($raw, 0, $size);
        $result    = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                [$k,$v] = explode(':', $line, 2);
                $result[trim($k)] = trim($v);
            }
        }
        return $result;
    }
    return curlRequest('POST', $url, $body, $headers);
}

function curlPutRaw(string $url, string $data, string $contentType): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ["Content-Type: $contentType", 'Content-Length: ' . strlen($data)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 300,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?? [];
}

function curlRequest(string $method, string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new RuntimeException('cURL error: ' . $err);
    return json_decode($res ?: '{}', true) ?? [];
}

/* ═══════════════════════════════════════════════════════════════════════════
   OUTPUT HELPERS
   ═════════════════════════════════════════════════════════════════════════ */
function jsonOut(array $data): void { echo json_encode($data); exit; }
function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}
