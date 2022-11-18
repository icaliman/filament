<?php

use Filament\Facades\Filament;
use Filament\Http\Controllers\Auth\EmailVerificationController;
use Filament\Http\Controllers\Auth\LogoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::name('filament.')
    ->group(function () {
        foreach (Filament::getContexts() as $context) {
            /** @var \Filament\Context $context */
            $contextId = $context->getId();

            Route::domain($context->getDomain())
                ->middleware($context->getMiddleware())
                ->name("{$contextId}.")
                ->prefix($context->getPath())
                ->group(function () use ($context) {
                    Route::name('auth.')->group(function () use ($context) {
                        if ($context->hasLogin()) {
                            $page = $context->getLoginPage();

                            if (class_exists($page)) {
                                Route::get('/login', $page)->name('login');
                            } else {
                                Route::redirect('/login', $page)->name('login');
                            }
                        }

                        if ($context->hasPasswordReset()) {
                            Route::name('password-reset.')
                                ->prefix('/password-reset')
                                ->group(function () use ($context) {
                                    Route::get('/request', $context->getRequestPasswordResetPage())->name('request');
                                    Route::get('/reset', $context->getResetPasswordPage())
                                        ->middleware(['signed'])
                                        ->name('reset');
                                });
                        }

                        if ($context->hasRegistration()) {
                            Route::get('/register', $context->getRegistrationPage())->name('register');
                        }

                        Route::post('/logout', LogoutController::class)->name('logout');
                    });

                    Route::middleware($context->getAuthMiddleware())
                        ->group(function () use ($context): void {
                            $hasRoutableTenancy = $context->hasRoutableTenancy();
                            $hasTenantRegistration = $context->hasTenantRegistration();
                            $tenantSlugField = $context->getTenantSlugField();

                            if ($hasRoutableTenancy) {
                                Route::get('/', function () use ($context, $hasTenantRegistration): RedirectResponse {
                                    $tenant = Filament::getUserDefaultTenant(Filament::auth()->user());

                                    if ($tenant) {
                                        return redirect($context->getUrl($tenant));
                                    }

                                    if (! $hasTenantRegistration) {
                                        abort(404);
                                    }

                                    return redirect($context->getTenantRegistrationUrl());
                                })->name('home');
                            }

                            if ($context->hasEmailVerification()) {
                                Route::name('auth.email-verification.')
                                    ->prefix('/email-verification')
                                    ->group(function () use ($context) {
                                        Route::get('/prompt', $context->getEmailVerificationPromptPage())->name('prompt');
                                        Route::get('/verify', EmailVerificationController::class)->name('verify');
                                    });
                            }

                            Route::name('tenant.')
                                ->group(function () use ($context, $hasTenantRegistration): void {
                                    if ($hasTenantRegistration) {
                                        $context->getTenantRegistrationPage()::routes($context);
                                    }
                                });

                            Route::prefix($hasRoutableTenancy ? ('{tenant' . (($tenantSlugField) ? ":{$tenantSlugField}" : '') . '}') : '')
                                ->group(function () use ($context): void {
                                    Route::get('/', function () use ($context): RedirectResponse {
                                        return redirect($context->getUrl(Filament::getTenant()));
                                    })->name('tenant');

                                    if ($context->hasTenantBilling()) {
                                        Route::get('/billing', $context->getTenantBillingProvider()->getRouteAction())
                                            ->name('tenant.billing');
                                    }

                                    Route::name('pages.')->group(function () use ($context): void {
                                        foreach ($context->getPages() as $page) {
                                            $page::routes($context);
                                        }
                                    });

                                    Route::name('resources.')->group(function () use ($context): void {
                                        foreach ($context->getResources() as $resource) {
                                            $resource::routes($context);
                                        }
                                    });

                                    if ($routes = $context->getAuthenticatedTenantRoutes()) {
                                        $routes($context);
                                    }
                                });

                            if ($routes = $context->getAuthenticatedRoutes()) {
                                $routes($context);
                            }
                        });

                    if ($routes = $context->getRoutes()) {
                        $routes($context);
                    }
                });
        }
    });
