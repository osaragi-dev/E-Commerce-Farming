<style>
    .fi-ta .fi-pagination-records-per-page-select-ctn {
        display: none;
    }

    /*
     * Bug: tombol "X" (clear) di Date Range filter numpuk sama teks
     * tanggal, soalnya tombolnya diposisikan absolute nempel ke kanan
     * tanpa ada padding buat ngasih ruang. CSS asli package ini nentuin
     * padding kiri-kanan sekaligus (padding-inline), jadi pakai
     * !important biar aturan kita pasti menang.
     */
    .fi-date-range-picker-input {
        padding-inline-end: 1.75rem !important;
    }

    /*
     * TCS revision (24/8): label hari (Sun/Mon/Tue/...) di kalender
     * Date Range kelihatan lebih besar dibanding angka tanggalnya
     * sendiri — dikecilin biar seragam & lebih rapi.
     */
    .fi-date-range-picker-calendar-header-day {
        font-size: 0.75rem !important;
    }
</style>