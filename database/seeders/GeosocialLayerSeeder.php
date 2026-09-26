<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeosocialLayerSeeder extends Seeder
{
    public function run(): void
    {
        $geoserverWms = config('services.geoserver.wms_url', 'http://localhost:8080/geoserver/wms');
        
        // Pastikan URL mengarah ke workspace geosocial/wms
        $geosocialWmsUrl = str_replace('/geoserver/wms', '/geoserver/geosocial/wms', $geoserverWms);
        if (!str_contains($geosocialWmsUrl, '/geosocial/wms')) {
            $geosocialWmsUrl = rtrim($geoserverWms, '/') . '/geosocial/wms';
        }

        $layers = [
            // ── 11 WMS Layers (GeoServer + PostGIS) ──
            [
                'layer_key'   => 'geosocial:geo_main_canal',
                'name'        => 'Main Canal (Kanal Utama)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_main_canal',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_main_river',
                'name'        => 'Main River (Sungai Utama)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_main_river',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_comunity_area',
                'name'        => 'Community Area (Pemukiman)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_comunity_area',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_agriculture_area',
                'name'        => 'Agriculture Area (Pertanian / Sawah)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_agriculture_area',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_rail_line',
                'name'        => 'Railway (Jalur Kereta)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_rail_line',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_province_road',
                'name'        => 'Province Road (Jalan Provinsi)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_province_road',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_administrative_line',
                'name'        => 'Administrative Boundary (Batas Wilayah)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_administrative_line',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_publicland',
                'name'        => 'Public Land (Tanah Publik)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_publicland',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_tol_81_line',
                'name'        => 'Toll 81 Line (Jalur Tol 81)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_tol_81_line',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_tol_area',
                'name'        => 'Toll Area (Area Tol)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_tol_area',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'geosocial:geo_barrage_line',
                'name'        => 'Barrage Line (Tanggul)',
                'type'        => 'wms',
                'url'         => $geosocialWmsUrl,
                'legend_url'  => $geosocialWmsUrl . '?service=WMS&request=GetLegendGraphic&format=image/png&layer=geosocial:geo_barrage_line',
                'is_active'   => true,
            ],

            // ── 8 Vector Point Layers (Clustered GeoJSON) ──
            [
                'layer_key'   => 'water_plan_point',
                'name'        => 'Water Plan Facilities (Fasilitas Air)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/water_plan_point',
                'legend_url'  => '#38bdf8',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'bridge_point',
                'name'        => 'Bridges (Jembatan)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/bridge_point',
                'legend_url'  => '#fb923c',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'evacuation_point',
                'name'        => 'Evacuation Points (Titik Evakuasi)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/evacuation_point',
                'legend_url'  => '#22c55e',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'water_gate_point',
                'name'        => 'Water Gates (Pintu Air)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/water_gate_point',
                'legend_url'  => '#06b6d4',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'weir_point',
                'name'        => 'Weirs (Bendung)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/weir_point',
                'legend_url'  => '#3b82f6',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'sewage_point',
                'name'        => 'Sewage Outlets (Saluran Limbah)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/sewage_point',
                'legend_url'  => '#78716c',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'solid_waste_point',
                'name'        => 'Solid Waste Points (TPS Limbah)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/solid_waste_point',
                'legend_url'  => '#a1a1aa',
                'is_active'   => true,
            ],
            [
                'layer_key'   => 'province_label',
                'name'        => 'Province Labels (Label Wilayah)',
                'type'        => 'vector',
                'url'         => '/api/geosocial/layers/points/province_label',
                'legend_url'  => '#64748b',
                'is_active'   => true,
            ],
        ];

        foreach ($layers as $layer) {
            DB::table('geosocial_layers')->updateOrInsert(
                ['layer_key' => $layer['layer_key']],
                array_merge($layer, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }
}