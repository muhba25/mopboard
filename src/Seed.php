<?php
declare(strict_types=1);

require_once __DIR__ . '/Templates.php';

/**
 * Seeds one demo project (subset of a real VM-migration MOP) on first run so
 * the dashboard has something to show immediately. Safe to run only once —
 * Database::get() only calls this when the sqlite file didn't exist yet.
 */
class Seed
{
    public static function run(PDO $pdo): void
    {
        $template = Templates::get('vm_migration');

        $pdo->prepare("INSERT INTO mop_projects (name, description) VALUES (?, ?)")
            ->execute([
                'Jadwal Migrasi VM – Nonprod (Contoh)',
                'Contoh project MOP migrasi VM Nonprod. Hapus atau import data Anda sendiri lewat menu Import.',
            ]);
        $projectId = (int) $pdo->lastInsertId();

        $order = 0;
        $colIds = [];
        $existingKeys = [];
        foreach ($template['columns'] as $col) {
            $key = slugify($col['label'], $existingKeys);
            $existingKeys[] = $key;
            $stmt = $pdo->prepare("
                INSERT INTO mop_columns (project_id, col_key, label, type, role, options, order_index, is_primary, required, width)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $projectId,
                $key,
                $col['label'],
                $col['type'],
                $col['role'],
                '[]',
                $order,
                !empty($col['is_primary']) ? 1 : 0,
                0,
                $col['width'] ?? null,
            ]);
            $colIds[] = $key;
            $order++;
        }

        // key order must match Templates::get('vm_migration')['columns']
        [
            $kNo, $kVm, $kHost, $kIp, $kSite, $kCluster, $kApp, $kPic,
            $kRepl, $kMalam, $kCutover, $kTim, $kStatusRepl, $kStatusCutover, $kTgl, $kKet
        ] = $colIds;

        $rows = [
            [1,'sarinadapp1','sarinadapp1','10.59.154.60','BSD','BSD-NONPROD-01','sarina','maulana_m_iqbal@telkomsel.co.id','22 Sep 2026 15:30 – 22 Sep 2026 18:30','Kamis, 24 Sep 2026','24 Sep 2026 22:00 – 22:31','Tim 1 - BSD','Ready','',''],
            [2,'selviottnfsappmedbsddapp1','selviottnfsappmedbsddapp1','10.59.41.171','BSD','BSD-NONPROD-01','SELVI OTT','adhitya_b_rizaldy@telkomsel.co.id','22 Sep 2026 18:30 – 22 Sep 2026 19:57','Kamis, 24 Sep 2026','24 Sep 2026 22:00 – 22:30','Tim 2 - BSD','Ready','',''],
            [3,'tbsnbudapp1-new','tbsnbudapp1','10.38.29.112','TBS','TBS-NONPROD-02','Veritas Netbackup','zulkhan_h_kurniawan@telkomsel.co.id','22 Sep 2026 15:30 – 23 Sep 2026 12:13','Kamis, 24 Sep 2026','24 Sep 2026 22:00 – 22:37','Tim 1 - TBS','Not Ready','',''],
            [4,'esbpcobsitpdb1','esbpcobsitpdb1.telkomsel.co.id','10.42.98.132','TBS','(Tanpa cluster)','Digital Core ESB','ahmad_a_lukman@telkomsel.co.id','22 Sep 2026 15:30 – 22 Sep 2026 15:46','Kamis, 24 Sep 2026','24 Sep 2026 22:00 – 22:30','Tim 2 - TBS','Not Ready','',''],
            [5,'cassandratbsnftdapp1','cassandratbsnftdapp1','10.59.219.27','TBS','TBS-NONPROD-02','Customer Order - Temporal Cassandra','rahmat_subagyo@telkomsel.co.id','22 Sep 2026 15:30 – 22 Sep 2026 16:54','Kamis, 24 Sep 2026','24 Sep 2026 22:30 – 23:00','Tim 2 - TBS','Ready','',''],
            [10,'ansibleedabsddapp1','ansibleedabsddapp1','10.59.154.86','BSD','BSD-NONPROD-01','Ansible','praditya_w_wardhana@telkomsel.co.id','22 Sep 2026 15:30 – 22 Sep 2026 16:27','Kamis, 24 Sep 2026','24 Sep 2026 23:00 – 23:31','Tim 2 - BSD','Ready','Done','24-Sep-26'],
            [22,'selviottdprcmedbsddapp1','selviottdprcmedbsddapp1','10.59.41.169','BSD','BSD-NONPROD-01','SELVI OTT','adhitya_b_rizaldy@telkomsel.co.id','23 Sep 2026 02:13 – 23 Sep 2026 02:57','Kamis, 24 Sep 2026','25 Sep 2026 00:32 – 01:02','Tim 2 - BSD','',''],
            [24,'rpadapp4-v2v','rpadapp4-v2v','10.49.9.140','TBS','TBS-NONPROD-01','RPA','Ika_Rahmawati@telkomsel.co.id','22 Sep 2026 19:32 – 22 Sep 2026 20:03','Kamis, 24 Sep 2026','25 Sep 2026 01:01 – 01:31','Tim 2 - TBS','confirm dismantle by Tower','confirm dismantle by Tower'],
            [30,'vmtest','vmtest','10.59.154.48','BSD','BSD-NONPROD-01','VMTEST','Galih_Hari@telkomsel.co.id','22 Sep 2026 17:25 – 22 Sep 2026 18:06','Kamis, 24 Sep 2026','25 Sep 2026 01:32 – 02:02','Tim 2 - BSD','done power off by ospla','done power off by ospla'],
            [31,'ISIGIDBTBSDDB3_rocky','isigidbtbsddb3','10.38.29.39','TBS','TBS-NONPROD-01','IDAM','bintang_d_mahardika@telkomsel.co.id','22 Sep 2026 20:03 – 22 Sep 2026 21:07','Kamis, 24 Sep 2026','25 Sep 2026 01:39 – 02:09','Tim 1 - TBS','Not Ready','','','Datastore berpindah'],
            [38,'redisctbspdb1','redisctbspdb1','10.38.29.98','TBS','TBS-NONPROD-01','Redis','resaldi_r_putra@telkomsel.co.id','22 Sep 2026 23:13 – 22 Sep 2026 23:46','Kamis, 24 Sep 2026','25 Sep 2026 02:39 – 03:09','Tim 1 - TBS','Failed connection refused by the node',''],
            [44,'selvidvdfbsddapp1','selvidvdfbsddapp1','10.59.41.180','BSD','BSD-NONPROD-01','SELVI OTT','adhitya_b_rizaldy@telkomsel.co.id','23 Sep 2026 07:08 – 23 Sep 2026 09:49','Jumat, 25 Sep 2026','25 Sep 2026 22:00 – 22:30','Tim 1 - BSD','',''],
            [58,'apmpocbsddapp5','apmpocbsddapp5','10.59.154.47','BSD','BSD-NONPROD-01','APM','Eryk_D_Saputro@telkomsel.co.id','23 Sep 2026 16:57 – 23 Sep 2026 17:58','Senin, 28 Sep 2026','28 Sep 2026 22:00 – 22:30','Tim 1 - BSD','',''],
            [60,'ansiblecontrollerbsddapp1','ansiblecontrollerbsddapp1','10.48.186.8','BSD','BSD-NONPROD-01','Tidak Terdeteksi','praditya_w_wardhana@telkomsel.co.id','22 Sep 2026 20:41 – 22 Sep 2026 22:12','Senin, 28 Sep 2026','28 Sep 2026 22:30 – 23:00','Tim 2 - BSD','Ready',''],
            [62,'hcportaldapp1_new','hcportaldapp1','10.250.200.119','BSD','BSD-NONPROD-01','HCP AW','zulkhan_h_kurniawan@telkomsel.co.id','22 Sep 2026 20:01 – 22 Sep 2026 20:41','Senin, 28 Sep 2026','28 Sep 2026 23:00 – 23:30','Tim 1 - BSD','Ready',''],
            [64,'ansibleautobsddapp1_restore','ansibleautobsddapp1','10.48.186.22','BSD','BSD-NONPROD-01','Isabel','Syaiful_N_Arifin@telkomsel.co.id','22 Sep 2026 22:12 – 22 Sep 2026 23:43','Senin, 28 Sep 2026','28 Sep 2026 23:30 – 00:01 (+1)','Tim 1 - BSD','Ready',''],
            [85,'nomadufotbs1workerdapp11','nomadufotbs1workerdapp11','10.38.29.187','TBS','TBS-NONPROD-02','Nomad','Galih_Hari@telkomsel.co.id','01 Oct 2026 05:44 – 01 Oct 2026 06:22','Kamis, 01 Oct 2026','02 Oct 2026 00:31 – 01:01','Tim 2 - TBS','Ready',''],
            [94,'ingwtapp2','ingwtapp2','10.38.19.99','TBS','TBS-NONPROD-01','iCharming','nafi_l_dirgayusa@telkomsel.co.id','01 Oct 2026 06:13 – 01 Oct 2026 07:15','Kamis, 01 Oct 2026','02 Oct 2026 02:32 – 03:02','Tim 2 - TBS','Ready','Done','24-Sep-26'],
            [98,'nomadservertbsdapp1','nomadservertbsdapp1','10.52.76.6','TBS','TBS-NONPROD-01','Nomad','Galih_Hari@telkomsel.co.id','01 Oct 2026 07:15 – 01 Oct 2026 07:54','Kamis, 01 Oct 2026','02 Oct 2026 03:03 – 03:33','Tim 1 - TBS','Failed connection refused by the node',''],
            [214,'nomadufotbs1workerdapp10_rocky','nomadufotbs1workerdapp10','10.38.29.186','TBS','TBS-NONPROD-01','Nomad','Galih_Hari@telkomsel.co.id','02 Oct 2026 13:44 – 02 Oct 2026 14:29','Senin, 12 Oct 2026','13 Oct 2026 00:01 – 00:31','Tim 2 - TBS','Ready',''],
            [267,'helpdeskwebdapp1_rocky','','','TBS','TBS-NONPROD-02','Remedy','Decy_IE_Nugroho@telkomsel.co.id','09 Oct 2026 05:18 – 09 Oct 2026 06:13','Rabu, 14 Oct 2026','15 Oct 2026 02:34 – 03:05','Tim 1 - TBS','Ready',''],
            [269,'pashtbsodapp1','pashtbsodapp1','10.59.219.68','TBS','TBS-NONPROD-02','Proof of Concept PASH','bintang_d_mahardika','09 Oct 2026 05:14 – 09 Oct 2026 05:52','Rabu, 14 Oct 2026','15 Oct 2026 03:05 – 03:35','Tim 1 - TBS','Ready',''],
            [270,'ISIGIVATBSDAPP2','','','TBS','TBS-NONPROD-02','IDAM','bintang_d_mahardika@telkomsel.co.id','09 Oct 2026 06:27 – 09 Oct 2026 07:00','Rabu, 14 Oct 2026','15 Oct 2026 03:05 – 03:35','Tim 2 - TBS','Ready',''],
            [272,'ngpamrdstbsdapp01','ngpamrdstbsdapp01.Development01.co.id','10.38.47.128','TBS','TBS-NONPROD-02','NGPAM','Fery_PH_Sinambela@telkomsel.co.id','09 Oct 2026 11:53 – 09 Oct 2026 12:19','Kamis, 15 Oct 2026','15 Oct 2026 22:00 – 22:30','Tim 2 - TBS','Ready',''],
            [273,'Erpematqapp1','erpematqapp1','10.38.19.52','TBS','TBS-NONPROD-02','SIENNA','Yeni_N_Izati@Telkomsel.co.id','10 Oct 2026 00:46 – 10 Oct 2026 02:54','Kamis, 15 Oct 2026','15 Oct 2026 22:30 – 23:00','Tim 2 - TBS','Ready',''],
            [282,'esbxwikitbsdapp1','esbxwikitbsdapp1','10.59.206.1','TBS','TBS-NONPROD-02','Digital Core ESB','ahmad_a_lukman@telkomsel.co.id','09 Oct 2026 04:08 – 09 Oct 2026 05:18','Kamis, 15 Oct 2026','16 Oct 2026 00:31 – 01:02','Tim 1 - TBS','Ready',''],
            [284,'ddsk8stbsdapp3_rocky','ddsk8stbsdapp3','10.38.47.98','TBS','TBS-NONPROD-02','Loan Marketplace','Muhammad_M_Zhafir@telkomsel.co.id','09 Oct 2026 06:55 – 09 Oct 2026 07:37','Kamis, 15 Oct 2026','16 Oct 2026 01:02 – 01:32','Tim 1 - TBS','Ready',''],
            [302,'vaultkmsdc1tbsdapp1-new','vaultkmsdc1tbsdapp1-new','10.52.76.93','TBS','TBS-NONPROD-02','Vault','Galih_Hari@telkomsel.co.id','09 Oct 2026 20:13 – 09 Oct 2026 20:49','Jumat, 16 Oct 2026','16 Oct 2026 23:00 – 23:31','Tim 2 - TBS','Ready','Done','24-Sep-26'],
            [450,'ansiblepocdapp1_rocky','ansiblepocdapp1','10.38.29.170','TBS','TBS-NONPROD-02','Ansible','praditya_w_wardhana@telkomsel.co.id','29 Oct 2026 08:29 – 29 Oct 2026 08:59','Jumat, 30 Oct 2026','30 Oct 2026 22:00 – 22:30','Tim 1 - TBS','Ready',''],
        ];

        $insertRow = $pdo->prepare("INSERT INTO mop_rows (project_id, row_no, data) VALUES (?,?,?)");
        $rowNo = 1;
        foreach ($rows as $r) {
            $data = [
                $kNo => $r[0],
                $kVm => $r[1],
                $kHost => $r[2],
                $kIp => $r[3],
                $kSite => $r[4],
                $kCluster => $r[5],
                $kApp => $r[6],
                $kPic => $r[7],
                $kRepl => $r[8],
                $kMalam => $r[9],
                $kCutover => $r[10],
                $kTim => $r[11],
                $kStatusRepl => $r[12] ?? '',
                $kStatusCutover => $r[13] ?? '',
                $kTgl => $r[14] ?? '',
                $kKet => $r[15] ?? '',
            ];
            $insertRow->execute([$projectId, $rowNo, json_encode($data, JSON_UNESCAPED_UNICODE)]);
            $rowNo++;
        }
    }
}
