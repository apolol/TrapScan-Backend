<?php

use App\Http\Controllers\APIController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\QRController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\StatsController;
use App\Http\Resources\CoordinatorSettingsResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\QR;
use App\Models\Trap;
use App\Models\User;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/*
 * Login Protected Routes
 */
Route::middleware('auth:sanctum')->group(function() {
    Route::get('/user',  function (Request $request) {
        return UserResource::make($request->user()->load('roles'));
    })->name('user.info');

    Route::prefix('my')->group(function() {
        Route::get('/inspectionsPerProject', function(Request $request) {
            return $request->user()->inspectionCountPerProject();
        });
        Route::post('/settings', function(Request $request) {
           $validated_data = $request->validate([
               'settings' => 'required|array'
           ]);
           return UserResource::make($request->user()->setSetting($validated_data['settings']));
        });
        Route::get('/coordinator/settings', function(Request $request) {
           $projects = $request->user()->isCoordinator();
           return CoordinatorSettingsResource::make($projects);
        });
        Route::post('/coordinator/settings', function(Request $request) {
           // TODO: Possibly check coordinator status here of request->user()
            $validated_data = $request->validate([
                'key' => 'required',
               'value' => 'required',
               'project_id' => 'required|exists:projects,id'
           ]);
            if($request->user()->setCoordinatorSettings($validated_data)) {
                return response()->json(['message' => 'Coordinator settings updated!'], 200);
            } else {
                return response()->json(['mesaage' => 'Error: Could not update coordinator settings'], 400);
            }
        });
        Route::post('/coordinator/catch/filter', function(Request $request) {
            // TODO: Possibly check coordinator status here of request->user()
            $validated_data = $request->validate([
                'catch_filter' => 'nullable|array',
                'project_id' => 'required|exists:projects,id'
            ]);
            if($request->user()->updateCatchFilter($validated_data)) {
                return response()->json(['message' => 'Catch filter updated!'], 200);
            } else {
                return response()->json(['mesaage' => 'Error: Could not update catch filter'], 400);
            }
        });
    });

    Route::get('/user/isCoordinator',  function (Request $request, Project $project) {
        $coord = $request->user()->isCoordinator();
        if(count($coord) > 0) {
            return response()->json(['project' => $coord], 200);
        } else {
            return response()->json([false], 400);
        }
    })->name('user.is.coordinator');

    Route::get('/user/isCoordinatorByTrap/{trap}',  function (Request $request, Trap $trap) {
        $project = $trap->project;
        if($request->user()->isCoordinatorOf($project)) {
            return response()->json([true], 200);
        } else {
            return response()->json([false], 400);
        }
    })->name('user.is.coordinator');

    Route::prefix('inspection')->group(function () {
        Route::post('/create', [InspectionController::class, 'create'])
            ->name('inspection.create');
        Route::get('/show/{inspection}', [InspectionController::class, 'show'])
            ->name('inspection.show');
    });

    /*
     * Admin Protected Routes
     */
    Route::prefix('admin')->middleware('role:admin')->group(function() {
        Route::post('/qr/create', [QRController::class, 'create'])
            ->name('admin.qr.create');
        Route::post('/qr/create/{project}', [QRController::class, 'createInProject'])
            ->name('admin.qr.create.project');
        Route::get('/qr/print/{qr:qr_code}', function (QR $qr) {
            return QrCode::size(500)->generate(env('SPA_URL') . '/scan/' . $qr->qr_code);
        });
        Route::get('/qr/all', function (QR $qr) {
            return Trap::whereNotNull('qr_id')->with('project')->get();
        });
        Route::get('/qr/unmapped', [QRController::class, 'unmapped'])
            ->name('admin.qr.unmapped');
        Route::get('/qr/unmapped/{project}', [QRController::class, 'unmappedInProject'])
            ->name('admin.qr.unmapped.project');
        Route::get('/nocode', [QRController::class, 'noCode'])
            ->name('admin.qr.unmapped.nocode');
    });

    /*
     * User Accessible QR routes
     */
    Route::post('/admin/qr/map', [QRController::class, 'mapQRCodeAdmin'])
        ->name('qr.map');

    /*
     * Field Scanning Code
     */
    Route::get('/scan/{qr_id}', [ScanController::class, 'scan'])
        ->name('scan.qr');

    Route::get('/nearby', function (Request $request) {
       $data = $request->validate([
           'lat' => 'required',
           'long' => 'required'
       ]);

       $userLocation = new Point($data['long'], $data['lat']);
       // Will find only a users projects traps
//       $project_ids = $request->user()->projects->pluck('id')->toArray();
//       return Trap::whereIn('project_id', $project_ids)
//           ->orderByDistance('coordinates', $userLocation, 'asc')
//           ->limit(5)
//           ->get();
        $coordinatorProjects = $request->user()->isCoordinator()->pluck('id')->toArray();

        return Trap::where('id', '>', '1201')
//            ->whereIn('project_id', $coordinatorProjects)->noCode()
            ->where(function($q) {
                $q->mapped();
            })
            ->orWhere(function($q) use($coordinatorProjects) {
                $q->whereIn('project_id', $coordinatorProjects)->noCode();
            })
            ->orderByDistance('coordinates', $userLocation, 'asc')
           ->limit(5)
           ->get();
    });
});

/*
 * Guest / Unprotected Routes
 */
http://localhost/api/inspection/anon/create
Route::post('/inspection/anon/create', [InspectionController::class, 'createAnon'])
    ->name('inspections.create.anon');

Route::prefix('anon')->group(function () {
    Route::get('/scan/{qr_id}', [ScanController::class, 'anonScan'])
        ->name('scan.qr');
});

Route::prefix('stats')->group(function () {
    Route::get('/kpi', [StatsController::class, 'kpis'])
        ->name('stats.kpi');
});

Route::get('/mail', function(Request $request) {
   $inspection = \App\Models\Inspection::find(20);
   $trap = $inspection->trap;
   $project = $trap->project;
   $user = $inspection->user;

   Mail::to('dylan@dylanhobbs.ie')
       ->send(new \App\Mail\TrapCatch($inspection, $project, $user, $trap));
});


