<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PublicPolicyController extends Controller
{
    public function privacyPolicy(): View
    {
        return view('legal.privacy-policy');
    }

    public function termsAndConditions(): View
    {
        return view('legal.terms-and-conditions');
    }
}
