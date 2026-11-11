<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;

class AuditController extends Controller
{
    public function __invoke()
    {
        return view('audit.index', ['events' => AuditEvent::with('actor:id,name')->orderByDesc('id')->paginate(25)]);
    }
}
