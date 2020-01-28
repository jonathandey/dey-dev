@extends('_layouts.master')

@push('meta')
    <meta property="og:title" content="About {{ $page->siteName }}" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="{{ $page->getUrl() }}"/>
    <meta property="og:description" content="A little bit about {{ $page->siteName }}" />
@endpush

@section('body')
    <h1>About</h1>

    <img src="/assets/img/about.png"
        alt="About image"
        class="flex rounded-full h-64 w-64 bg-contain mx-auto md:float-right my-6 md:ml-10">

    <p class="mb-6">
        The personal website of Jonathan Dey - welcome!
    </p>

    <p class="mb-6">
        I have been programming for over 20 years. Mostly programming in PHP my software engineering career began working on a mafia themed MMORPG when I was in Secondary School.
    </p>

    <p class="mb-6">
        I have worked for companies of all sizes, from a local design &amp; marketing agency to a National ISP.<br>
        Now based in Lincoln, UK I started 2020 by co-founding a company with a colleague I met when we both attended Lincoln College.
    </p>

    <p class="mb-6">
        My passion for web development has yet to find its end. I enjoy solving problems and digging in to new technology &amp; code.
    </p>

@endsection
