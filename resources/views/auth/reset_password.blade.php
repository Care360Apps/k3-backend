@extends('layout.mainlayout',['activePage' => 'login'])
@section('title',__('Reset Password'))
@section('content')
<div class="xl:w-2/4 mx-auto pt-20 pb-20 xxsm:mx-5 xl:mx-auto">
    @if (! empty($done))
        <h1 class="font-fira-sans leading-10 font-medium text-3xl">{{ __('Your password has been changed.') }}</h1>
        <p class="font-fira-sans text-sm pt-4">{{ __('You can now sign in with your new password in the app or on the website. Other devices have been signed out.') }}</p>
    @else
        <h1 class="font-fira-sans leading-10 font-medium text-3xl">{{ __('Choose a new password') }}</h1>
        @if ($errors->any())
            <div class="text-red-600 text-sm pt-4">{{ $errors->first() }}</div>
        @endif
        <form action="{{ url('/reset-password') }}" method="post">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="pt-5">
                <label for="email" class="font-fira-sans text-black text-sm font-normal">{{ __('Email') }}</label>
                <input id="email" name="email" value="{{ old('email', $email) }}" class="w-full text-sm font-fira-sans text-gray block p-2 border border-white-light" required type="email">
            </div>
            <div class="pt-5">
                <label for="password" class="font-fira-sans text-black text-sm font-normal">{{ __('New password') }}</label>
                <input id="password" name="password" class="w-full text-sm font-fira-sans text-gray block p-2 border border-white-light" required minlength="8" type="password" autocomplete="new-password">
            </div>
            <div class="pt-5">
                <label for="password_confirmation" class="font-fira-sans text-black text-sm font-normal">{{ __('Confirm new password') }}</label>
                <input id="password_confirmation" name="password_confirmation" class="w-full text-sm font-fira-sans text-gray block p-2 border border-white-light" required minlength="8" type="password" autocomplete="new-password">
            </div>
            <div class="pt-10">
                <button type="submit" class="font-fira-sans text-white bg-primary w-full text-sm font-normal py-3">{{ __('Change password') }}</button>
            </div>
        </form>
    @endif
</div>
@endsection
