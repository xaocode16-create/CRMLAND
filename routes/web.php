<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->middleware('can:dashboard.view')->name('dashboard');
    Route::livewire('leads', 'pages::leads.index')->middleware('can:leads.view')->name('leads.index');
    Route::livewire('leads/{lead}', 'pages::leads.show')->middleware('can:leads.view')->name('leads.show');
    Route::livewire('pipeline', 'pages::pipeline.index')->middleware('can:pipeline.view')->name('pipeline.index');
    Route::livewire('properties', 'pages::properties.index')->middleware('can:properties.view')->name('properties.index');
    Route::livewire('properties/{property}', 'pages::properties.show')->middleware('can:properties.view')->name('properties.show');
    Route::livewire('follow-ups', 'pages::follow-ups.index')->middleware('can:followups.view')->name('follow-ups.index');
    Route::redirect('followups', 'follow-ups');
    Route::livewire('calendar', 'pages::calendar.index')->middleware('can:appointments.view')->name('calendar.index');
    Route::livewire('reservations', 'pages::reservations.index')->middleware('can:appointments.view')->name('reservations.index');
    Route::livewire('sales', 'pages::sales.index')->middleware('can:sales.view')->name('sales.index');
    Route::livewire('commissions', 'pages::commissions.index')->middleware('can:commissions.view')->name('commissions.index');
    Route::livewire('reports', 'pages::reports.index')->middleware('can:reports.view')->name('reports.index');
    Route::livewire('assistant', 'pages::assistant')->middleware('can:dashboard.view')->name('assistant');
    Route::livewire('users', 'pages::users.index')->middleware('can:users.view')->name('users.index');
    Route::livewire('roles', 'pages::roles.index')->middleware('can:roles.view')->name('roles.index');
    Route::livewire('permissions', 'pages::permissions.index')->middleware('can:permissions.view')->name('permissions.index');
    Route::livewire('notifications', 'pages::notifications.index')->middleware('can:notifications.view')->name('notifications.index');
});

require __DIR__.'/settings.php';
