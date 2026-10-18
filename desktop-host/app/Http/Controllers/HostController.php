<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\Settings;

class HostController extends Controller
{
    private const ALIAS = 'classroom-server';

    private function settings(): array
    {
        return Settings::get('classroom', [
            'project' => 'C:\\dev\\laravel projects\\ccis-faculty-evaluation',
            'php' => 'C:\\php\\php.exe', 'port' => 8088, 'autostart' => true,
        ]);
    }

    public function index()
    {
        return view('host', ['settings' => $this->settings()]);
    }

    public function status()
    {
        $process = ChildProcess::get(self::ALIAS);
        $file = storage_path('app/host-status.json');
        $state = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        if (! $process && ($state['state'] ?? null) !== 'error') {
            $state = ['state' => 'stopped', 'message' => 'Start the server to share the system on your Wi-Fi.', 'links' => []];
        }

        return response()->json($state ?? ['state' => 'starting', 'message' => 'Starting…', 'links' => []]);
    }

    public function start()
    {
        if (! ChildProcess::get(self::ALIAS)) {
            $settings = $this->settings();
            file_put_contents(storage_path('app/host-status.json'), json_encode(['state' => 'starting', 'message' => 'Starting the classroom server…', 'links' => []]));
            ChildProcess::node([base_path('resources/server/host.cjs'), $settings['project'], $settings['php'], (string) $settings['port'], storage_path('app/host-status.json')], self::ALIAS);
        }

        return $this->status();
    }

    public function stop()
    {
        if (ChildProcess::get(self::ALIAS)) {
            ChildProcess::stop(self::ALIAS);
        }
        file_put_contents(storage_path('app/host-status.json'), json_encode(['state' => 'stopped', 'message' => 'Server stopped. Connected devices cannot access the system until you start it again.', 'links' => []]));

        return $this->status();
    }

    public function save(Request $request)
    {
        abort_if(ChildProcess::get(self::ALIAS) !== null, 422, 'Stop the server before changing its settings.');
        $data = $request->validate(['project' => 'required|string|max:500', 'php' => 'required|string|max:500', 'port' => 'required|integer|min:1024|max:65524', 'autostart' => 'required|boolean']);
        abort_unless(is_file($data['project'].'/artisan') && is_file($data['project'].'/vendor/autoload.php'), 422, 'Select the installed classroom project folder.');
        abort_unless(is_file($data['php']) && strtolower(pathinfo($data['php'], PATHINFO_EXTENSION)) === 'exe', 422, 'Select a valid PHP executable.');
        Settings::set('classroom', $data);

        return response()->json(['saved' => true]);
    }
}
