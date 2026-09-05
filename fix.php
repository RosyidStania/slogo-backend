$events = \App\Models\Event::all();
foreach($events as $e) {
    $cats = is_string($e->target_kategori) ? json_decode($e->target_kategori, true) : $e->target_kategori;
    if (is_array($cats) && in_array('PENGURUS', $cats)) {
        $cats[array_search('PENGURUS', $cats)] = 'PENGURUS USMAN';
        $e->target_kategori = is_string($e->target_kategori) ? json_encode($cats) : $cats;
        $e->save();
        echo 'Updated event: ' . $e->id . PHP_EOL;
    }
}

$types = \App\Models\EventType::all();
foreach($types as $t) {
    $cats = is_string($t->target_kategori) ? json_decode($t->target_kategori, true) : $t->target_kategori;
    if (is_array($cats) && in_array('PENGURUS', $cats)) {
        $cats[array_search('PENGURUS', $cats)] = 'PENGURUS USMAN';
        $t->target_kategori = is_string($t->target_kategori) ? json_encode($cats) : $cats;
        $t->save();
        echo 'Updated event type: ' . $t->id . PHP_EOL;
    }
}
