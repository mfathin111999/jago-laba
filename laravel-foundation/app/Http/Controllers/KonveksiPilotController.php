<?php

namespace App\Http\Controllers;

use App\Http\Requests\KonveksiPilotRequest;
use App\Pilots\Konveksi\KonveksiPilotReadService;
use Inertia\Inertia;
use Inertia\Response;

final class KonveksiPilotController
{
    public function __construct(private readonly KonveksiPilotReadService $pilot) {}

    public function __invoke(KonveksiPilotRequest $request): Response
    {
        $companyKey = $request->companyKey();

        return Inertia::render('Pilot/Konveksi', $this->pilot->overview($companyKey, $request->tab()));
    }
}
