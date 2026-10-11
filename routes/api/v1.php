<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Baseline\BaselineController;
use App\Http\Controllers\Api\V1\Correction\CorrectionAuditController;
use App\Http\Controllers\Api\V1\Correction\EpCorrectionController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\Ep\AllowanceController;
use App\Http\Controllers\Api\V1\Ep\CompensationController;
use App\Http\Controllers\Api\V1\Ep\EcController;
use App\Http\Controllers\Api\V1\Ep\EpController;
use App\Http\Controllers\Api\V1\Ep\EpicadController;
use App\Http\Controllers\Api\V1\Ep\EpicadDesignController;
use App\Http\Controllers\Api\V1\Ep\Nested;
use App\Http\Controllers\Api\V1\Ep\PaymentController;
use App\Http\Controllers\Api\V1\Ep\VoucherController;
use App\Http\Controllers\Api\V1\EpidCode\EpidCodeController;
use App\Http\Controllers\Api\V1\GrantAssistanceController;
use App\Http\Controllers\Api\V1\Import\EpImportController;
use App\Http\Controllers\Api\V1\Map\MapController;
use App\Http\Controllers\Api\V1\Master\MasterController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Rate\RateController;
use App\Http\Controllers\Api\V1\RateFile\RateFileController;
use App\Http\Controllers\Api\V1\Report\ReportController;
use App\Http\Controllers\Api\V1\System\HealthController;
use App\Http\Controllers\Api\V1\Validation\ValidationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| All routes live under /api/v1 and use the `api` middleware group
| (throttle:api from AppServiceProvider). Endpoints gated by a permission
| code use middleware('permission:<code>'); endpoints behind a mandatory
| password change use middleware('force.password.change') (architecture §3,
| §2.3).
|
*/

Route::get('health', HealthController::class)->name('health');

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');

// ── Password reset — public, throttled (architecture §2.3) ──────
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1')
    ->name('auth.forgot-password');

Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1')
    ->name('auth.reset-password');

// Authenticated endpoints (Bearer token or Sanctum stateful cookie).
// force.password.change blocks data access for users with a temp password
// (architecture §2.3) — only auth.me, auth.change-password, and auth.logout
// are whitelisted inside the middleware.
Route::middleware(['auth:sanctum', 'force.password.change'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'register'])
        ->middleware('permission:ep.view')
        ->name('payments.register');
    Route::get('auth/me', [AuthController::class, 'me'])
        ->name('auth.me');

    Route::post('auth/change-password', [AuthController::class, 'changePassword'])
        ->middleware(['force.password.change', 'throttle:5,1'])
        ->name('auth.change-password');

    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('force.password.change')
        ->name('auth.logout');

    // ── Master data (architecture §5.2) ────────────────────────────
    Route::prefix('masters')->name('masters.')->group(function (): void {
        // ── Hierarchy shortcuts (must be before {entity} wildcard) ──
        Route::get('upazilas', [MasterController::class, 'upazilas'])
            ->middleware('permission:master.view')
            ->name('upazilas');

        Route::get('villages', [MasterController::class, 'villages'])
            ->middleware('permission:master.view')
            ->name('villages');

        Route::get('{entity}', [MasterController::class, 'index'])
            ->middleware('permission:master.view')
            ->name('index');

        Route::get('{entity}/{id}', [MasterController::class, 'show'])
            ->middleware('permission:master.view')
            ->name('show');

        Route::post('{entity}', [MasterController::class, 'store'])
            ->middleware('permission:master.manage')
            ->name('store');

        Route::put('{entity}/{id}', [MasterController::class, 'update'])
            ->middleware('permission:master.manage')
            ->name('update');

        Route::delete('{entity}/{id}', [MasterController::class, 'destroy'])
            ->middleware('permission:master.manage')
            ->name('destroy');
    });

    // ── Rate lookup (assessment dropdowns) — Phase G ───────────────
    Route::prefix('rates')->name('rates.')->group(function (): void {
        Route::get('{type}', [RateController::class, 'index'])
            ->middleware('permission:master.view')
            ->name('index');
    });

    // ── EC Rate File (Admin → Rate Management) ─────────────────────
    // Reference rates the EC File engine uses for compensation. Read is
    // open to master.view (same as other masters); write is admin only.
    Route::prefix('rate-file')->name('rate-file.')->group(function (): void {
        Route::get('material-options', [RateFileController::class, 'materialOptions'])
            ->middleware('permission:master.view')
            ->name('material-options');

        Route::get('usage-type-options', [RateFileController::class, 'usageTypeOptions'])
            ->middleware('permission:master.view')
            ->name('usage-type-options');

        Route::get('mouza-options', [RateFileController::class, 'mouzaOptions'])
            ->middleware('permission:master.view')
            ->name('mouza-options');

        Route::get('land-category-options', [RateFileController::class, 'landCategoryOptions'])
            ->middleware('permission:master.view')
            ->name('land-category-options');

        Route::post('{type}', [RateFileController::class, 'store'])
            ->middleware('permission:master.manage')
            ->name('store');

        Route::get('{type}', [RateFileController::class, 'index'])
            ->middleware('permission:master.view')
            ->name('index');

        Route::put('{type}/{id}', [RateFileController::class, 'update'])
            ->middleware('permission:master.manage')
            ->name('update');

        Route::delete('{type}/{id}', [RateFileController::class, 'destroy'])
            ->middleware('permission:master.manage')
            ->name('destroy');
    });

    // ── Grant and Assistance (Admin → Grant & Assistance) ─────────
    // Resettlement grant / assistance items (LDC, DA, SRC, HTG, HCG,
    // CAVHH, AVFHH, MTG, SGfT, GfWL) used by the EC File calculation.
    // Read is open to master.view; write is admin only (master.manage).
    Route::prefix('grant-assistance')->name('grant-assistance.')->group(function (): void {
        Route::get('/', [GrantAssistanceController::class, 'index'])
            ->middleware('permission:master.view')
            ->name('index');

        Route::put('{grant_code}', [GrantAssistanceController::class, 'update'])
            ->middleware('permission:master.manage')
            ->name('update');
    });

    // ── Projects (architecture §5.3) ───────────────────────────────
    // GET endpoints are open to any authenticated user so everyone can
    // pick a project to work on after login. Create/update require
    // master.manage (admin).
    Route::prefix('projects')->name('projects.')->group(function (): void {
        Route::get('/', [ProjectController::class, 'index'])
            ->name('index');

        Route::post('/', [ProjectController::class, 'store'])
            ->middleware('permission:master.manage')
            ->name('store');

        Route::get('{project}', [ProjectController::class, 'show'])
            ->name('show');

        Route::put('{project}', [ProjectController::class, 'update'])
            ->middleware('permission:master.manage')
            ->name('update');

        Route::delete('{project}', [ProjectController::class, 'destroy'])
            ->middleware('permission:master.manage')
            ->name('destroy');
    });

    // ── EP ID codes (Admin → EP ID Codes) ─────────────────────────
    // Configurable short codes the EpIdService consumes when building EP IDs.
    Route::prefix('ep-id-codes')->name('ep-id-codes.')->group(function (): void {
        Route::get('categories', [EpidCodeController::class, 'categories'])
            ->middleware('permission:master.view')
            ->name('categories');

        Route::get('/', [EpidCodeController::class, 'index'])
            ->middleware('permission:master.view')
            ->name('index');

        Route::post('/', [EpidCodeController::class, 'store'])
            ->middleware('permission:master.manage')
            ->name('store');

        Route::put('{epid_code}', [EpidCodeController::class, 'update'])
            ->middleware('permission:master.manage')
            ->name('update');

        Route::delete('{epid_code}', [EpidCodeController::class, 'destroy'])
            ->middleware('permission:master.manage')
            ->name('destroy');
    });

    // ── EP / Affected Person (architecture §5.4) ──────────────────
    Route::prefix('ep')->name('ep.')->group(function (): void {
        Route::get('/', [EpController::class, 'index'])
            ->middleware('permission:ep.view')
            ->name('index');

        Route::post('/', [EpController::class, 'store'])
            ->middleware('permission:ep.create')
            ->name('store');

        Route::get('lookup', [EpController::class, 'lookup'])
            ->middleware('permission:ep.view')
            ->name('lookup');

        Route::get('lookup', [EpController::class, 'lookup'])
            ->middleware('permission:ep.view')
            ->name('lookup');

        // Additional-payment queue — PAID EPs that already have new loss data
        // (land, crop, structure, tree, business) waiting for a 2nd voucher.
        // Must be declared BEFORE the dynamic '{ep}' route, otherwise the
        // request would be swallowed by ep.show's model binding (404).
        Route::get('additional-payment-pending', [VoucherController::class, 'additionalPending'])
            ->middleware('permission:ep.view')
            ->name('additional.pending');

        // Bulk delete all DRAFT EPs (must be declared before {ep} dynamic route)
        Route::delete('draft', [EpController::class, 'destroyDrafts'])
            ->middleware('permission:ep.delete')
            ->name('discard-drafts');

        Route::get('{ep}', [EpController::class, 'show'])
            ->middleware('permission:ep.view')
            ->name('show');

        Route::put('{ep}', [EpController::class, 'update'])
            ->middleware('permission:ep.edit')
            ->name('update');

        Route::delete('{ep}', [EpController::class, 'destroy'])
            ->middleware('permission:ep.delete')
            ->name('destroy');

        Route::post('{ep}/submit', [EpController::class, 'submit'])
            ->middleware('permission:ep.submit')
            ->name('submit');

        Route::post('{ep}/verify', [EpController::class, 'verify'])
            ->middleware('permission:ep.verify')
            ->name('verify');

        Route::post('{ep}/approve', [EpController::class, 'approve'])
            ->middleware('permission:ep.approve')
            ->name('approve');

        Route::get('{ep}/history', [EpController::class, 'history'])
            ->middleware('permission:ep.view')
            ->name('history');

        Route::get('{ep}/file-data', [EpController::class, 'fileData'])
            ->middleware('permission:ep.view')
            ->name('fileData');

        // ── Nested entities under EP (architecture §5.5–5.11) ────────
        // Original nested records are immutable after payment. Additional
        // rows created for a PAID EP remain editable until voucher approval.

        // Family Members
        Route::prefix('{ep}/family-member')->name('family-member.')->group(function (): void {
            Route::get('/', [Nested\FamilyMemberController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\FamilyMemberController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{family_member}', [Nested\FamilyMemberController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{family_member}', [Nested\FamilyMemberController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{family_member}', [Nested\FamilyMemberController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Land
        Route::prefix('{ep}/land')->name('land.')->group(function (): void {
            Route::get('/', [Nested\LandController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\LandController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{land}', [Nested\LandController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{land}', [Nested\LandController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{land}', [Nested\LandController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Crop (nested under Land)
        Route::prefix('{ep}/land/{land}/crop')->name('crop.')->group(function (): void {
            Route::get('/', [Nested\CropController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\CropController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{crop}', [Nested\CropController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{crop}', [Nested\CropController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{crop}', [Nested\CropController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Infrastructure
        Route::prefix('{ep}/infrastructure')->name('infrastructure.')->group(function (): void {
            Route::get('/', [Nested\InfrastructureController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\InfrastructureController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{infrastructure}', [Nested\InfrastructureController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{infrastructure}', [Nested\InfrastructureController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{infrastructure}', [Nested\InfrastructureController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Tree
        Route::prefix('{ep}/tree')->name('tree.')->group(function (): void {
            Route::get('/', [Nested\TreeController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\TreeController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{tree}', [Nested\TreeController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{tree}', [Nested\TreeController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{tree}', [Nested\TreeController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Tenant
        Route::prefix('{ep}/tenant')->name('tenant.')->group(function (): void {
            Route::get('/', [Nested\TenantController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\TenantController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{tenant}', [Nested\TenantController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{tenant}', [Nested\TenantController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{tenant}', [Nested\TenantController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Business
        Route::prefix('{ep}/business')->name('business.')->group(function (): void {
            Route::get('/', [Nested\BusinessController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\BusinessController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{business}', [Nested\BusinessController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{business}', [Nested\BusinessController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{business}', [Nested\BusinessController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // Worker
        Route::prefix('{ep}/worker')->name('worker.')->group(function (): void {
            Route::get('/', [Nested\WorkerController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\WorkerController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{worker}', [Nested\WorkerController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{worker}', [Nested\WorkerController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{worker}', [Nested\WorkerController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');

            // Worker Assessment (manual amount, no rate master)
            Route::get('{worker}/assessment', [Nested\WorkerAssessmentController::class, 'show'])
                ->middleware('permission:ep.view')->name('assessment.show');
            Route::post('{worker}/assessment', [Nested\WorkerAssessmentController::class, 'store'])
                ->middleware('permission:ep.edit')->name('assessment.store');
            Route::put('{worker}/assessment', [Nested\WorkerAssessmentController::class, 'update'])
                ->middleware('permission:ep.edit')->name('assessment.update');
            Route::delete('{worker}/assessment', [Nested\WorkerAssessmentController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('assessment.destroy');
        });

        // Business
        Route::prefix('{ep}/business')->name('business.')->group(function (): void {
            Route::get('/', [Nested\BusinessController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\BusinessController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{business}', [Nested\BusinessController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{business}', [Nested\BusinessController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{business}', [Nested\BusinessController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');

            // Business Loss (manual amount, no rate master)
            Route::get('{business}/loss', [Nested\BusinessLossController::class, 'show'])
                ->middleware('permission:ep.view')->name('loss.show');
            Route::post('{business}/loss', [Nested\BusinessLossController::class, 'store'])
                ->middleware('permission:ep.edit')->name('loss.store');
            Route::put('{business}/loss', [Nested\BusinessLossController::class, 'update'])
                ->middleware('permission:ep.edit')->name('loss.update');
            Route::delete('{business}/loss', [Nested\BusinessLossController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('loss.destroy');
        });

        // ── EPICAD (architecture §5.2) ───────────────────────────
        Route::get('{ep}/epicad', [EpicadController::class, 'show'])
            ->middleware('permission:ep.view')->name('epicad.show');
        Route::post('{ep}/epicad', [EpicadController::class, 'store'])
            ->middleware('permission:ep.edit')->name('epicad.store');
        Route::put('{ep}/epicad', [EpicadController::class, 'update'])
            ->middleware('permission:ep.edit')->name('epicad.update');
        Route::delete('{ep}/epicad', [EpicadController::class, 'destroy'])
            ->middleware('permission:ep.edit')->name('epicad.destroy');
        Route::post('{ep}/epicad/print', [EpicadController::class, 'print'])
            ->middleware('permission:ep.edit')->name('epicad.print');

        // ── EC (architecture §5.3) ───────────────────────────────
        Route::get('{ep}/ec', [EcController::class, 'show'])
            ->middleware('permission:ep.view')->name('ec.show');
        Route::post('{ep}/ec', [EcController::class, 'store'])
            ->middleware('permission:ep.edit')->name('ec.store');
        Route::put('{ep}/ec', [EcController::class, 'update'])
            ->middleware('permission:ep.edit')->name('ec.update');
        Route::post('{ep}/ec/submit', [EcController::class, 'submit'])
            ->middleware('permission:ep.submit')->name('ec.submit');
        Route::post('{ep}/ec/verify', [EcController::class, 'verify'])
            ->middleware('permission:ep.verify')->name('ec.verify');
        Route::post('{ep}/ec/approve', [EcController::class, 'approve'])
            ->middleware('permission:ep.approve')->name('ec.approve');
        Route::post('{ep}/ec/reject', [EcController::class, 'reject'])
            ->middleware('permission:ec.reject')->name('ec.reject');
        Route::get('{ep}/ec/history', [EcController::class, 'history'])
            ->middleware('permission:ep.view')->name('ec.history');
        Route::post('{ep}/ec/recalculate', [EcController::class, 'recalculate'])
            ->middleware('permission:ep.edit')->name('ec.recalculate');

        // ── EC Line Items (nested under EC) ──────────────────────
        Route::prefix('{ep}/ec/line-item')->name('ec.line-item.')->group(function (): void {
            Route::get('/', [Nested\EcLineItemController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [Nested\EcLineItemController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::put('{line_item}', [Nested\EcLineItemController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{line_item}', [Nested\EcLineItemController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
        });

        // ── Land Assessment (nested under Land) ──────────────────
        Route::get('{ep}/land/{land}/assessment', [Nested\LandAssessmentController::class, 'show'])
            ->middleware('permission:ep.view')->name('land.assessment.show');
        Route::post('{ep}/land/{land}/assessment', [Nested\LandAssessmentController::class, 'store'])
            ->middleware('permission:ep.edit')->name('land.assessment.store');
        Route::put('{ep}/land/{land}/assessment', [Nested\LandAssessmentController::class, 'update'])
            ->middleware('permission:ep.edit')->name('land.assessment.update');
        Route::delete('{ep}/land/{land}/assessment', [Nested\LandAssessmentController::class, 'destroy'])
            ->middleware('permission:ep.edit')->name('land.assessment.destroy');

        // ── Crop Assessment (nested under Crop) ─────────────────
        Route::get('{ep}/land/{land}/crop/{crop}/assessment', [Nested\CropAssessmentController::class, 'show'])
            ->middleware('permission:ep.view')->name('crop.assessment.show');
        Route::post('{ep}/land/{land}/crop/{crop}/assessment', [Nested\CropAssessmentController::class, 'store'])
            ->middleware('permission:ep.edit')->name('crop.assessment.store');
        Route::put('{ep}/land/{land}/crop/{crop}/assessment', [Nested\CropAssessmentController::class, 'update'])
            ->middleware('permission:ep.edit')->name('crop.assessment.update');
        Route::delete('{ep}/land/{land}/crop/{crop}/assessment', [Nested\CropAssessmentController::class, 'destroy'])
            ->middleware('permission:ep.edit')->name('crop.assessment.destroy');

        // ── Infrastructure Assessment (nested under Infrastructure) ──
        Route::get('{ep}/infrastructure/{infrastructure}/assessment', [Nested\InfrastructureAssessmentController::class, 'show'])
            ->middleware('permission:ep.view')->name('infrastructure.assessment.show');
        Route::post('{ep}/infrastructure/{infrastructure}/assessment', [Nested\InfrastructureAssessmentController::class, 'store'])
            ->middleware('permission:ep.edit')->name('infrastructure.assessment.store');
        Route::put('{ep}/infrastructure/{infrastructure}/assessment', [Nested\InfrastructureAssessmentController::class, 'update'])
            ->middleware('permission:ep.edit')->name('infrastructure.assessment.update');
        Route::delete('{ep}/infrastructure/{infrastructure}/assessment', [Nested\InfrastructureAssessmentController::class, 'destroy'])
            ->middleware('permission:ep.edit')->name('infrastructure.assessment.destroy');

        // ── Tree Assessment (nested under Tree) ──────────────────
        Route::get('{ep}/tree/{tree}/assessment', [Nested\TreeAssessmentController::class, 'show'])
            ->middleware('permission:ep.view')->name('tree.assessment.show');
        Route::post('{ep}/tree/{tree}/assessment', [Nested\TreeAssessmentController::class, 'store'])
            ->middleware('permission:ep.edit')->name('tree.assessment.store');
        Route::put('{ep}/tree/{tree}/assessment', [Nested\TreeAssessmentController::class, 'update'])
            ->middleware('permission:ep.edit')->name('tree.assessment.update');
        Route::delete('{ep}/tree/{tree}/assessment', [Nested\TreeAssessmentController::class, 'destroy'])
            ->middleware('permission:ep.edit')->name('tree.assessment.destroy');

        // ── Allowance (vulnerability-based grant, per EP) ─────
        Route::prefix('{ep}/allowance')->name('allowance.')->group(function (): void {
            Route::get('/', [AllowanceController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [AllowanceController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{allowance}', [AllowanceController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{allowance}', [AllowanceController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{allowance}', [AllowanceController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
            Route::post('{allowance}/approve', [AllowanceController::class, 'approve'])
                ->middleware('permission:ep.approve')->name('approve');
            Route::post('{allowance}/reject', [AllowanceController::class, 'reject'])
                ->middleware('permission:ep.approve')->name('reject');
            Route::post('{allowance}/mark-paid', [AllowanceController::class, 'markPaid'])
                ->middleware('permission:ep.approve')->name('mark-paid');
        });

        // ── Compensation (per EC, approved amount per category) ─
        Route::prefix('{ep}/compensation')->name('compensation.')->group(function (): void {
            Route::get('/', [CompensationController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [CompensationController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::post('additional', [CompensationController::class, 'additional'])
                ->middleware('permission:ep.edit')->name('additional');
            Route::get('{compensation}', [CompensationController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{compensation}', [CompensationController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{compensation}', [CompensationController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
            Route::post('{compensation}/approve', [CompensationController::class, 'approve'])
                ->middleware('permission:ep.approve')->name('approve');
            Route::post('{compensation}/cancel', [CompensationController::class, 'cancel'])
                ->middleware('permission:ep.approve')->name('cancel');
        });

        // ── Voucher (payment batch per EC) ──────────────────────
        Route::prefix('{ep}/voucher')->name('voucher.')->group(function (): void {
            Route::get('/', [VoucherController::class, 'index'])
                ->middleware('permission:ep.view')->name('index');
            Route::post('/', [VoucherController::class, 'store'])
                ->middleware('permission:ep.edit')->name('store');
            Route::get('{voucher}', [VoucherController::class, 'show'])
                ->middleware('permission:ep.view')->name('show');
            Route::put('{voucher}', [VoucherController::class, 'update'])
                ->middleware('permission:ep.edit')->name('update');
            Route::delete('{voucher}', [VoucherController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('destroy');
            Route::post('{voucher}/verify', [VoucherController::class, 'verify'])
                ->middleware('permission:ep.submit')->name('verify');
            Route::post('{voucher}/approve', [VoucherController::class, 'approve'])
                ->middleware('permission:ep.approve')->name('approve');
            Route::post('{voucher}/cancel', [VoucherController::class, 'cancel'])
                ->middleware('permission:ep.approve')->name('cancel');
            Route::post('{voucher}/compensation', [VoucherController::class, 'attachCompensation'])
                ->middleware('permission:ep.edit')->name('compensation.attach');

            // ── Payment (nested under Voucher) ──────────────
            Route::get('{voucher}/payment', [PaymentController::class, 'index'])
                ->middleware('permission:ep.view')->name('payment.index');
            Route::post('{voucher}/payment', [PaymentController::class, 'store'])
                ->middleware('permission:ep.edit')->name('payment.store');
            Route::delete('{voucher}/payment/{payment}', [PaymentController::class, 'destroy'])
                ->middleware('permission:ep.edit')->name('payment.destroy');
        });
    });

    // ── Baseline data browser (architecture §5.4–§5.11) ─────────
    Route::prefix('baseline')->name('baseline.')->group(function (): void {
        Route::get('eps', [BaselineController::class, 'eps'])->name('eps');
        Route::get('family-members', [BaselineController::class, 'familyMembers'])->name('family-members');
        Route::get('lands', [BaselineController::class, 'lands'])->name('lands');
        Route::get('crops', [BaselineController::class, 'crops'])->name('crops');
        Route::get('infrastructures', [BaselineController::class, 'infrastructures'])->name('infrastructures');
        Route::get('trees', [BaselineController::class, 'trees'])->name('trees');
        Route::get('tenants', [BaselineController::class, 'tenants'])->name('tenants');
        Route::get('businesses', [BaselineController::class, 'businesses'])->name('businesses');
        Route::get('workers', [BaselineController::class, 'workers'])->name('workers');
        Route::get('gps', [BaselineController::class, 'gps'])->name('gps');
    })->middleware('permission:ep.view');

    // ── Excel import (admin-only: bulk EP upload) ───────────────
    Route::prefix('imports')->name('imports.')->group(function (): void {
        Route::get('ep/template', [EpImportController::class, 'template'])
            ->middleware('permission:ep.import')->name('ep.template');
        Route::post('ep', [EpImportController::class, 'import'])
            ->middleware('permission:ep.import')->name('ep.import');
        Route::post('ep/step', [EpImportController::class, 'importStep'])
            ->middleware('permission:ep.import')->name('ep.import.step');
        Route::delete('ep/step/{step}', [EpImportController::class, 'clearStep'])
            ->middleware('permission:ep.import')->name('ep.clear.step');
        Route::get('ep/step/{step}/count', [EpImportController::class, 'countStepData'])
            ->middleware('permission:ep.import')->name('ep.count.step');
    });

    // ── EPICAD designs / templates (EPICAD tab) ────────────────
    Route::prefix('epicad-designs')->name('epicad-designs.')->group(function (): void {
        Route::get('/', [EpicadDesignController::class, 'index'])
            ->middleware('permission:ep.view')->name('index');
        Route::post('/', [EpicadDesignController::class, 'store'])
            ->middleware('permission:ep.edit')->name('store');
        Route::get('{epicadDesign}', [EpicadDesignController::class, 'show'])
            ->middleware('permission:ep.view')->name('show');
        Route::put('{epicadDesign}', [EpicadDesignController::class, 'update'])
            ->middleware('permission:ep.edit')->name('update');
        Route::delete('{epicadDesign}', [EpicadDesignController::class, 'destroy'])
            ->middleware('permission:ep.delete')->name('destroy');
        Route::post('{epicadDesign}/upload', [EpicadDesignController::class, 'upload'])
            ->middleware('permission:ep.edit')->name('upload');
    });

    // ── Users / Roles (Admin — architecture §5.12) ──────────────
    Route::prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [UserController::class, 'index'])
            ->middleware('permission:user.manage')->name('index');
        Route::post('/', [UserController::class, 'store'])
            ->middleware('permission:user.manage')->name('store');
        Route::put('{user}', [UserController::class, 'update'])
            ->middleware('permission:user.manage')->name('update');
        Route::delete('{user}', [UserController::class, 'destroy'])
            ->middleware('permission:user.manage')->name('destroy');
        Route::post('{user}/assign-role', [UserController::class, 'assignRole'])
            ->middleware('permission:user.manage')->name('assign-role');
    });

    Route::prefix('roles')->name('roles.')->group(function (): void {
        Route::get('/', [RoleController::class, 'index'])
            ->middleware('permission:user.manage')->name('index');
        Route::post('/', [RoleController::class, 'store'])
            ->middleware('permission:role.manage')->name('store');
        Route::post('{role}/permissions', [RoleController::class, 'assignPermissions'])
            ->middleware('permission:role.manage')->name('permissions');
    });

    // ── Map (architecture §17, §5.11 #74–75) ────────────────────
    Route::prefix('map')->name('map.')->group(function (): void {
        Route::get('surveys', [MapController::class, 'surveys'])
            ->middleware('permission:map.view')->name('surveys');
        Route::get('clusters', [MapController::class, 'clusters'])
            ->middleware('permission:map.view')->name('clusters');
    });

    // ── Reports (architecture §18, §5.11 #72–73) ────────────────
    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('{type}', [ReportController::class, 'index'])
            ->middleware('permission:report.view')
            ->name('index');

        Route::get('{type}/export', [ReportController::class, 'export'])
            ->middleware('permission:report.export')
            ->name('export');
    });

    // ── Dashboard statistics (Phase 4B-9) ──────────────────────
    Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
        Route::get('stats', [DashboardController::class, 'stats'])
            ->middleware('permission:report.view')
            ->name('stats');
    });

    // ── Validation API (Phase 4B-9) ────────────────────────────
    Route::prefix('validation')->name('validation.')->group(function (): void {
        Route::get('ep/{ep}', [ValidationController::class, 'validateEp'])
            ->middleware('permission:ep.view')
            ->name('ep');

        Route::get('project/{project}/eps', [ValidationController::class, 'validateProjectEps'])
            ->middleware('permission:ep.view')
            ->name('project-eps');

        Route::get('ep/{ep}/epicad-readiness', [ValidationController::class, 'epicadReadiness'])
            ->middleware('permission:ep.view')
            ->name('epicad-readiness');

        Route::get('ep/{ep}/ec-readiness', [ValidationController::class, 'ecReadiness'])
            ->middleware('permission:ep.view')
            ->name('ec-readiness');

        Route::get('ep/{ep}/voucher-readiness', [ValidationController::class, 'voucherReadiness'])
            ->middleware('permission:ep.view')
            ->name('voucher-readiness');

        Route::get('project/{project}/summary', [ValidationController::class, 'projectSummary'])
            ->middleware('permission:ep.view')
            ->name('project-summary');

        // ── List invalid EPs (paginated, with specific issues) ──
        Route::get('eps/invalid', [ValidationController::class, 'invalidEps'])
            ->middleware('permission:ep.view')
            ->name('invalid-eps');
    });

    // ── Data correction audit (Phase 4B-9) ─────────────────────
    Route::prefix('corrections')->name('corrections.')->group(function (): void {
        Route::get('/', [CorrectionAuditController::class, 'index'])
            ->middleware('permission:ep.view')
            ->name('index');

        Route::post('/', [CorrectionAuditController::class, 'store'])
            ->middleware('permission:ep.edit')
            ->name('store');

        // ── Admin correction workflow for APPROVED EPs ─────────────
        // Static segments are declared before the dynamic
        // {entityType}/{entityId}/history route so "eps" and "history" are
        // never swallowed by the wildcard.
        Route::get('eps', [EpCorrectionController::class, 'index'])
            ->middleware('permission:ep.view')
            ->name('eps.index');

        Route::get('history', [EpCorrectionController::class, 'history'])
            ->middleware('permission:ep.view')
            ->name('history.list');

        Route::delete('eps/{ep}', [EpCorrectionController::class, 'destroy'])
            ->middleware('permission:ep.delete')
            ->name('eps.destroy');

        Route::get('{entityType}/{entityId}/history', [CorrectionAuditController::class, 'history'])
            ->middleware('permission:ep.view')
            ->name('history');
    });
});
