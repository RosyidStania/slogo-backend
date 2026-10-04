const fs = require('fs');
const filepath = 'app/Http/Controllers/Api/EventTypeController.php';
let content = fs.readFileSync(filepath, 'utf8');

// Replace index
content = content.replace(
    "    public function index()\n    {\n        return response()->json(['success' => true, 'data' => EventType::orderBy('name', 'asc')->get()], 200);\n    }",
    `    public function index(Request $request)
    {
        $user = $request->user();
        $query = EventType::orderBy('name', 'asc');
        
        // Jika yang login MT, hanya tampilkan kategori yang di-set untuk absensi perkelompok
        if ($user && $user->role === 'mt') {
            $query->where('is_group_attendance', true);
        }
        
        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }`
);

// Replace validations
content = content.replace(
    "'description' => 'nullable|string'",
    "'description' => 'nullable|string',\n            'is_group_attendance' => 'boolean'"
);
content = content.replace(
    "'description' => 'nullable|string'",
    "'description' => 'nullable|string',\n            'is_group_attendance' => 'boolean'"
);

// Replace creations & updates
content = content.replace(
    "'description' => $request->description",
    "'description' => $request->description,\n            'is_group_attendance' => $request->is_group_attendance ?? false"
);
content = content.replace(
    "'description' => $request->description",
    "'description' => $request->description,\n            'is_group_attendance' => $request->is_group_attendance ?? false"
);

fs.writeFileSync(filepath, content, 'utf8');
console.log("Updated EventTypeController");
