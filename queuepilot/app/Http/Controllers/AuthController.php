<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Session;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect(Auth::isSuper() ? '/admin' : '/app');
            return;
        }
        view('auth/login', ['title' => 'Sign in', 'email' => '']);
    }

    public function login(): void
    {
        $email = Request::string('email');
        $password = (string) Request::input('password', '');

        if ($email === '' || $password === '') {
            flash('error', 'Enter your email and password.');
            Session::flash('old', ['email' => $email]);
            redirect('/login');
            return;
        }

        [$ok, , $reason] = Auth::attempt($email, $password, Request::ip());

        if (!$ok) {
            flash('error', $reason === 'too_many'
                ? 'Too many attempts. Please wait a few minutes before trying again.'
                : 'Those details did not match an active account.');
            Session::flash('old', ['email' => $email]);
            redirect('/login');
            return;
        }

        $intended = Session::takeFlash('intended');
        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : (Auth::isSuper() ? '/admin' : '/app');

        $u = Auth::user();
        flash('success', 'Welcome back, ' . explode(' ', (string) $u['name'])[0] . '.');
        redirect($target);
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Csrf::rotate();
        flash('success', 'You have been signed out.');
        redirect('/login');
    }

    public function locale(): void
    {
        $locale = Request::string('locale', 'en');
        $locale = in_array($locale, ['en', 'si', 'ta'], true) ? $locale : 'en';
        Session::put('locale', $locale);
        Lang::load($locale);
        back('/app');
    }
}
