<?php

/**
 * The Konveksi collections ("sheets"). Konveksi began as a Google Apps Script app on Google Sheets, so every
 * sheet is one table `jl_data_<key>` whose rows hold the whole record as JSON (`_data_json`).
 *
 * Each entry is [sheet name as it appears in the source workbook, collection key]. The table name is always
 * `jl_data_<key>`; system_table_map maps sheet name -> key -> table (the order below is the order of the legacy map).
 */
return [
    'collections' => [
        ['Rekening', 'rekening'],
        ['Material_Alias', 'material_alias'],
        ['Bahan_HPP_History', 'bahan_hpp_history'],
        ['Marketplace_Product_Mapping', 'marketplace_product_mapping'],
        ['Marketplace_Sync_Queue', 'marketplace_sync_queue'],
        ['Penjualan', 'penjualan'],
        ['Penjualan_Item', 'penjualan_item'],
        ['Penjualan_Koreksi', 'penjualan_koreksi'],
        ['Template_Dokumen', 'template_dokumen'],
        ['HPP_Model_BOM', 'hpp_model_bom'],
        ['HPP_History', 'hpp_history'],
        ['Hutang_Konveksi', 'hutang_konveksi'],
        ['Riwayat_Bayar_Hutang_Konveksi', 'riwayat_bayar_hutang_konveksi'],
        ['Master_Stok', 'master_stok'],
        ['Mutasi_Stok', 'mutasi_stok'],
        ['Pembelian_Vendor', 'pembelian_vendor'],
        ['Riwayat_Bayar_Hutang', 'riwayat_bayar_hutang'],
        ['Pengguna', 'pengguna'],
        ['Hak_Akses', 'hak_akses'],
        ['Bahan_HPP', 'bahan_hpp'],
        ['Pembelian_Item', 'pembelian_item'],
        ['Potong_Item', 'potong_item'],
        ['Jahit_Item', 'jahit_item'],
        ['Quality_Control', 'quality_control'],
        ['Mutasi_Stok_Konveksi', 'mutasi_stok_konveksi'],
        ['HutangSupplier', 'hutang_supplier'],
        ['Produk', 'produk'],
        ['Bahan', 'bahan'],
        ['Pegawai', 'pegawai'],
        ['Supplier', 'supplier'],
        ['Pembelian', 'pembelian'],
        ['Potong', 'potong'],
        ['Jahit', 'jahit'],
        ['Keuangan', 'keuangan'],
        ['Kasbon', 'kasbon'],
        ['Profil', 'profil'],
        ['Distribusi', 'distribusi'],
        ['_README', 'readme'],
    ],

    /**
     * Tables the legacy installer also creates although no system_table_map row points to them. `hutangsupplier`
     * is the spelling of the same sheet as `hutang_supplier` (the legacy store maps one onto the other); it is
     * kept so a database built by migrations has the same tables as one built by the legacy installer.
     */
    'unmapped_tables' => ['jl_data_hutangsupplier'],
];
