<?php

// Throwaway probe for stage 1 exit criterion 6: this test fails on purpose
// to demonstrate that a failing isolation test blocks CI. Never merge.

it('deliberately fails to prove the isolation suite gates the build', function () {
    expect(true)->toBeFalse();
});
