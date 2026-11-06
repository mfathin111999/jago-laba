<?php

namespace App\Http\Controllers;

use App\Http\Responses\UnavailableResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PilotPageController
{
    public function marketplace(Request $request): RedirectResponse|Response
    {
        $tab = strtolower(trim((string) $request->query('tab', 'dashboard')));

        if ($tab === 'integrasi') {
            return redirect()->route('marketplace.integrations', $this->query($request, ['store', 'store_id', 'oauth_status', 'oauth_message', 'oauth_code', 'pending'], false), 303);
        }

        if ($tab !== 'dashboard') {
            return UnavailableResponse::unavailable('page');
        }

        return redirect()->route('pilot.marketplace', $this->query($request, [
            'store', 'store_id', 'tab', 'scope', 'period', 'date_start', 'date_end', 'finance_mode',
        ]), 303);
    }

    public function konveksi(Request $request): RedirectResponse|Response
    {
        $tab = strtolower(trim((string) $request->query('tab', 'dashboard')));

        if ($tab !== 'dashboard') {
            return UnavailableResponse::unavailable('page');
        }

        return redirect()->route('pilot.konveksi', ['tab' => 'dashboard'], 303);
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function query(Request $request, array $allowed, bool $forceDashboard = true): array
    {
        $query = [];
        foreach ($allowed as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $query[$key] = (string) $value;
            }
        }

        if ($forceDashboard) {
            $query['tab'] = 'dashboard';
        }

        return $query;
    }
}
