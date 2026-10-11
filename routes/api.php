<?php

use Illuminate\Support\Facades\Route;

// Versioned API entry point (architecture §5).
// URL prefix /api/v1 is applied inside the v1 group.
Route::prefix('v1')->group(base_path('routes/api/v1.php'));
