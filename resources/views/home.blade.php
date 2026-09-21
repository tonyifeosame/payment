@extends('layouts.marketing')

{{-- Public homepage. Story order: school → students → fees → payment → receipt → records.
     Every section is a partial under resources/views/marketing/home. --}}

@section('content')
    @include('marketing.home.hero')
    @include('marketing.home.trust-strip')
    @include('marketing.home.features')
    @include('marketing.home.payment-flow')
    @include('marketing.home.showcase')
    @include('marketing.home.for-schools')
    @include('marketing.home.for-parents')
    @include('marketing.home.security')
    @include('marketing.home.cta')
@endsection
