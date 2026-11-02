<?php
// bot.php — ИИ + билеты + VIP безлимит

const TG_TOKEN = '9097802584:AAFy324CT8p_-jaCg7ZJORrF7z14xdq4sgg';
const ADMIN_ID = 8976822191;
const PUB_AI_URL = 'https://pubai.pubtalkteam.workers.dev';
const PUB_AI_KEY = 'pub_pubai';
const PUB_AI_MODEL = 'pubex1/meta-llama/Llama-3.1-8B-Instruct';
const STATE_FILE = __DIR__ . '/bot_state.json';

const TICKET_LIMIT = 3; // бесплатных поисков билетов

function tg(string $method, array $params = []): ?array {
    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 65,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) { echo "CURL error: $err\n"; return null; }
    return json_decode($resp, true);
}

function sendMessage(int $chatId, string $text, ?array $keyboard = null): void {
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    tg('sendMessage', $params);
}

function loadState(): array {
    if (!file_exists(STATE_FILE)) return [];
    $data = json_decode(file_get_contents(STATE_FILE), true);
    return is_array($data) ? $data : [];
}
function saveState(array $state): void {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function isVip(int $userId): bool {
    $state = loadState();
    return isset($state[$userId]['vip_until']) && $state[$userId]['vip_until'] > time();
}

function askAI(string $message): string {
    $payload = json_encode(['message' => $message, 'model' => PUB_AI_MODEL, 'stream' => false]);
    $ch = curl_init(PUB_AI_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . PUB_AI_KEY],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return 'Ошибка ИИ (HTTP ' . $code . ')';
    $data = json_decode($resp, true);
    return $data['reply'] ?? '(ИИ не дал ответа)';
}

// ============ ДАТА ============
function parseDate(string $text): DateTime {
    $text = mb_strtolower($text);
    $now = new DateTime('now');

    if (mb_strpos($text, 'послезавтра') !== false) return (clone $now)->modify('+2 days');
    if (mb_strpos($text, 'завтра') !== false) return (clone $now)->modify('+1 day');
    if (mb_strpos($text, 'сегодня') !== false) return $now;

    if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?\b/u', $text, $m)) {
        $day = (int)$m[1]; $month = (int)$m[2];
        $year = isset($m[3]) ? (int)$m[3] : (int)$now->format('Y');
        if ($year < 100) $year += 2000;
        $date = DateTime::createFromFormat('Y-n-j', "$year-$month-$day");
        if ($date) {
            if ($date < (clone $now)->setTime(0,0)) $date->modify('+1 year');
            return $date;
        }
    }

    $months = ['января'=>1,'февраля'=>2,'марта'=>3,'апреля'=>4,'мая'=>5,'июня'=>6,
               'июля'=>7,'августа'=>8,'сентября'=>9,'октября'=>10,'ноября'=>11,'декабря'=>12];
    if (preg_match('/\b(\d{1,2})\s+([а-яё]+)\b/u', $text, $m)) {
        $day = (int)$m[1]; $monthName = $m[2];
        if (isset($months[$monthName])) {
            $month = $months[$monthName];
            $year = (int)$now->format('Y');
            $date = DateTime::createFromFormat('Y-n-j', "$year-$month-$day");
            if ($date && $date < (clone $now)->setTime(0,0)) $date->modify('+1 year');
            if ($date) return $date;
        }
    }
    return $now;
}

// ============ ПАССАЖИРЫ ============
function parsePassengers(string $text): int {
    $text = mb_strtolower($text);
    if (preg_match('/\b(\d+)\s*(билет|пассажир|человек|взросл)/u', $text, $m)) return max(1, (int)$m[1]);
    if (preg_match('/на\s+(двоих|троих|четверых|пятерых)/u', $text, $m)) {
        $map = ['двоих'=>2,'троих'=>3,'четверых'=>4,'пятерых'=>5];
        return $map[$m[1]] ?? 1;
    }
    if (preg_match('/\b(один|одна|два|две|три|четыре|пять)\b/u', $text, $m)) {
        $map = ['один'=>1,'одна'=>1,'два'=>2,'две'=>2,'три'=>3,'четыре'=>4,'пять'=>5];
        return $map[$m[1]] ?? 1;
    }
    return 1;
}

// ============ БИЛЕТЫ ============
function searchTickets(string $text): ?string {
    $text = mb_strtolower($text);
    if (mb_strpos($text, 'билет') === false) return null;

    if (!preg_match('/из\s+([а-яёa-z\-]+)\s+в\s+([а-яёa-z\-]+)/u', $text, $m)) {
        return "Не понял маршрут. Напишите так: «найди билеты из Оренбурга в Москву 12.11 на 2 человека»";
    }

    $from = trim($m[1]); $to = trim($m[2]);
    $codes = [
        'оренбург'=>'REN','оренбурга'=>'REN','оренбурге'=>'REN',
        'москва'=>'MOW','москву'=>'MOW','москвы'=>'MOW','москве'=>'MOW',
        'санкт-петербург'=>'LED','питер'=>'LED','спб'=>'LED',
        'казань'=>'KZN','казани'=>'KZN','новосибирск'=>'OVB','екатеринбург'=>'SVX',
        'сочи'=>'AER','самара'=>'KUF','уфа'=>'UFA','краснодар'=>'KRR',
    ];
    $fromCode = $codes[$from] ?? null;
    $toCode = $codes[$to] ?? null;

    if (!$fromCode || !$toCode) {
        return "Не знаю код аэропорта для «{$from}» или «{$to}». Уточните города (например: Оренбург, Москва).";
    }

    $date = parseDate($text);
    $passengers = parsePassengers($text);
    $dateStr = $date->format('dmy');
    $dateHuman = $date->format('d.m.Y');
    $url = "https://www.aviasales.ru/search/{$fromCode}{$dateStr}{$toCode}{$passengers}";

    return "✈️ <b>Билеты {$from} → {$to}</b>\n"
         . "📅 Дата: {$dateHuman}\n"
         . "👥 Пассажиров: {$passengers}\n"
         . "🔗 {$url}\n\n"
         . "(Откроется Aviasales с нужной датой и количеством пассажиров)";
}

// ================= ОСНОВНОЙ ЦИКЛ =================
echo "Бот запущен. VIP даёт безлимит на билеты.\n";
$offset = 0;

while (true) {
    $updates = tg('getUpdates', ['offset' => $offset, 'timeout' => 60]);
    if (!$updates || !($updates['ok'] ?? false)) {
        echo "Ошибка получения обновлений. Сплю 5 сек...\n";
        sleep(5);
        continue;
    }

    foreach ($updates['result'] as $update) {
        $offset = $update['update_id'] + 1;

        // --- Callback (кнопка "Оплатил") ---
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $fromId = $cb['from']['id'];
            tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
            $state = loadState();
            $text = "💰 <b>Заявка на VIP!</b>\nПользователь: <code>{$fromId}</code>\n"
                  . "Телефон: " . ($state[$fromId]['phone'] ?? 'не указан') . "\n"
                  . "Для подтверждения: <code>/approve {$fromId} 30</code>";
            sendMessage(ADMIN_ID, $text);
            sendMessage($fromId, "✅ Заявка отправлена администратору. Ожидайте активации.");
            continue;
        }

        if (!isset($update['message'])) continue;
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $fromId = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        if ($text === '') continue;

        echo "Получено от {$fromId}: {$text}\n";
        $state = loadState();

        // /start
        if ($text === '/start') {
            sendMessage($chatId, "Привет! Я ИИ-бот с поиском билетов.\n\n"
                . "Пример: <code>@pbai_robot найди билеты из Оренбурга в Москву 12.11 на 2 человека</code>\n\n"
                . "Бесплатно: " . TICKET_LIMIT . " поиска билетов.\n"
                . "VIP — безлимит. Команда /vip");
            continue;
        }

        // /limit
        if ($text === '/limit') {
            $vip = isVip($fromId);
            $used = $state[$fromId]['ticket_used'] ?? 0;
            $left = max(0, TICKET_LIMIT - $used);
            $vipInfo = $vip ? "активен до " . date('d.m.Y H:i', $state[$fromId]['vip_until']) : "нет";
            sendMessage($chatId, "VIP: <b>{$vipInfo}</b>\nБесплатных поисков осталось: <b>{$left}</b> из " . TICKET_LIMIT);
            continue;
        }

        // /vip
        if ($text === '/vip') {
            $state[$fromId]['step'] = 'ask_phone';
            saveState($state);
            sendMessage($chatId, "Для оформления VIP отправьте ваш <b>точный номер телефона</b> (например, +79001234567).");
            continue;
        }

        // /approve (админ)
        if ($fromId === ADMIN_ID && preg_match('/^\/approve\s+(\d+)\s+(\d+)/', $text, $m)) {
            $targetId = (int)$m[1]; $days = (int)$m[2];
            $state[$targetId]['vip_until'] = time() + ($days * 86400);
            saveState($state);
            sendMessage($targetId, "✅ VIP-подписка активирована на {$days} дней! Поиск билетов теперь безлимитный.");
            sendMessage($chatId, "VIP для {$targetId} активирован на {$days} дней.");
            continue;
        }

        // ожидание телефона
        if (($state[$fromId]['step'] ?? '') === 'ask_phone') {
            if (!preg_match('/[\d\s\+\-\(\)]{10,}/', $text)) {
                sendMessage($chatId, "Пожалуйста, отправьте корректный номер (минимум 10 цифр).");
                continue;
            }
            $state[$fromId]['phone'] = $text;
            $state[$fromId]['step'] = '';
            saveState($state);
            $keyboard = ['inline_keyboard' => [
                [['text' => '1 месяц — 100₽', 'callback_data' => 'vip_paid']],
                [['text' => '3 месяца — 250₽', 'callback_data' => 'vip_paid']],
                [['text' => '6 месяцев — 450₽', 'callback_data' => 'vip_paid']],
            ]];
            sendMessage($chatId, "Номер сохранён.\n\nРеквизиты: <code>2200150268588719</code>\n"
                . "Переведите нужную сумму и нажмите тариф ниже, чтобы сообщить админу об оплате.", $keyboard);
            continue;
        }

        // упоминание или личка
        $shouldRespond = ($message['chat']['type'] === 'private') || (mb_strpos($text, '@pbai_robot') !== false);
        if (!$shouldRespond) continue;
        $text = trim(str_replace('@pbai_robot', '', $text));

        // билеты
        $tickets = searchTickets($text);
        if ($tickets) {
            $vip = isVip($fromId);
            if (!$vip) {
                $used = $state[$fromId]['ticket_used'] ?? 0;
                if ($used >= TICKET_LIMIT) {
                    sendMessage($chatId, "❌ Бесплатные поиски закончились (" . TICKET_LIMIT . " шт).\n"
                        . "Оформите /vip для безлимита — или продолжайте общаться с ИИ.");
                    continue;
                }
                $state[$fromId]['ticket_used'] = $used + 1;
                saveState($state);
                $left = TICKET_LIMIT - $state[$fromId]['ticket_used'];
                sendMessage($chatId, $tickets . "\n\n<i>Осталось бесплатных поисков: {$left}</i>");
            } else {
                sendMessage($chatId, $tickets . "\n\n<i>VIP: безлимит</i>");
            }
            continue;
        }

        // ИИ
        sendMessage($chatId, askAI($text));
    }
}
