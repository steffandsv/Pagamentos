<?php
function formatMoney($val) {
    return 'R$ ' . number_format($val, 2, ',', '.');
}

function formatDate($date) {
    return date('d/m/Y', strtotime($date));
}
