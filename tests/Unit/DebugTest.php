<?php

use LBHurtado\SettlementEnvelope\Services\DriverService;

beforeEach(function () {
    $this->service = new DriverService;
    $this->composedHomeLoanDriver = 'bank.home-loan.base';
});

test('debug spatie data config is loaded', function () {
    expect(config('data'))->toBeArray()
        ->and(config('data.validation_strategy'))->not->toBeNull();
});