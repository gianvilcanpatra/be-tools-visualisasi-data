<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Kelola Dashboard</title>

    <!-- Favicons -->
    <link rel="icon" type="image/x-icon" href="{{ asset('assets/img/logo/ArutalaHitam.png') }}">
    <link href="{{ asset('assets/img/apple-touch-icon.png') }}" rel="apple-touch-icon">


    <!-- Template Main CSS File -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Memuat DataTables JS -->
    <script src="https://cdn.datatables.net/1.12.1/js/jquery.dataTables.min.js"></script>

    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.12.1/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/dashboard.css') }}" rel="stylesheet">
</head>

<body>
    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="logo">
            <img src="{{ asset('assets/img/logo/ArutalaHitam.png') }}" alt="">
        </div>

        <div class="d-flex flex-column">
            <span>Dashboard ATMS</span>
            <div class="d-flex">
                <span>Halaman 1 dari 3</span>
                <span>|</span>
                <span id="menu-data">Pilih Data</span>
                <span>|</span>
                <span id="menu-visualisasi">Pilih Visualisasi</span>
            </div>
        </div>

    </header>

    <!-- ======= Sidebar ======= -->
    <div class="sidebar-container">
        <!-- Sidebar 1 -->
        <div id="sidebar" class="sidebar">

            <div class="sub-title">
                <img src="{{ asset('assets/img/icons/Storage.png') }}" alt="">
                <span class="sub-text">Data</span>
            </div>
            <hr class="full-line">
            <div class="accordion" id="tableAccordion"></div>
            <div id="loading" class="alert alert-info">Loading...</div>
        </div>

        <!-- Sidebar 2 -->
        <div id="sidebar-data" class="sidebar-2">
            <div class="sub-title">
                <img src="{{ asset('assets/img/icons/ChartPieOutline.png') }}" alt="">
                <span class="sub-text">Data</span>
            </div>
            <hr class="full-line">

            <div class="form-diagram">
                <div class="form-group">
                    <span>Dimensi</span>
                    <div id="dimensi-container">
                        <input type="text" class="dimensi-input" onchange="fetchData()">
                    </div>
                    <button type="button" class="btn btn-secondary mt-2" onclick="addDimensi()">Tambah Dimensi</button>
                </div>
            
                <div class="form-group">
                    <span>Metrik</span>
                    <input type="text" id="metrik-input" onchange="fetchData()">
                </div>
                <div class="form-group">
                    <span>Tanggal</span>
                    <input type="text" id="tanggal-input" onchange="fetchData()">
                </div>
            
                <div class="form-group">
                    <span>Filter</span>
                    <input type="text" id="filter-input" onchange="fetchData()">
                </div>
            </div>
            
        </div>

        <div id="sidebar-diagram" class="sidebar-2">
            <div class="sub-title">
                <img src="{{ asset('assets/img/icons/ChartPieOutline.png') }}" alt="">
                <span class="sub-text">Diagram</span>
            </div>
            <hr class="full-line">
            <div class="form-diagram">
                <div class="form-group">
                    <span>Batang</span>
                    <div class="card-row">
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                    </div>
                </div>

                <div class="form-group">
                    <span>Kolom</span>
                    <div class="card-row">
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                    </div>
                </div>

                <div class="form-group">
                    <span>Pie</span>
                    <div class="card-row">
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                        <div class="mini-card"></div>
                    </div>
                </div>
            </div>
        </div>

        <main class="main-container">
            <div class="canvas" id="canvas">
                <!-- Tempat untuk menampilkan tabel -->
                <div id="tableContainer" style="padding: 20px; margin: 10px; border: 1px solid #ffffff;">
                    <!-- Tabel akan ditampilkan di sini -->
                </div>
            </div>
        </main>
        
    </div>


    <script>
        let scale = 1;
        const zoomSpeed = 0.005;
        const canvas = document.getElementById('canvas');
        const mainContainer = document.querySelector('.main-container');

        mainContainer.addEventListener('wheel', (event) => {
            if (event.ctrlKey) { // Zoom hanya terjadi saat Ctrl ditekan
                event.preventDefault();
                scale += event.deltaY * -zoomSpeed;
                scale = Math.min(Math.max(0.5, scale), 3); // Batas zoom min 0.5x, max 3x
                canvas.style.transform = `scale(${scale})`;

                // Memastikan main-container bisa di-scroll dengan benar
                mainContainer.style.overflow = "auto";
            }
        });
    </script>

    <!-- Vendor JS Files -->
    <script src="{{ asset('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
    {{-- <script src="{{ asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script> --}}
    <script src="{{ asset('assets/vendor/chart.js/chart.umd.js') }}"></script>
    <script src="{{ asset('assets/vendor/echarts/echarts.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/quill/quill.js') }}"></script>
    <script src="{{ asset('assets/vendor/simple-datatables/simple-datatables.js') }}"></script>
    <script src="{{ asset('assets/vendor/tinymce/tinymce.min.js') }}"></script>
    <script src="{{ asset('assets/vendor/php-email-form/validate.js') }}"></script>



    <!-- Template Main JS File -->
    <script src="{{ asset('assets/js/dashboard.js') }}"></script>
    <script>
        let selectedTable = null; 

// Fungsi untuk menambah kolom input dimensi
function addDimensi() {
    const dimensiContainer = document.getElementById('dimensi-container');
    const newInput = document.createElement('input');
    newInput.type = 'text';
    newInput.classList.add('dimensi-input');
    newInput.setAttribute('onchange', 'fetchData()');
    dimensiContainer.appendChild(newInput);
}

function fetchData() {
    const dimensiInputs = document.querySelectorAll('.dimensi-input');
    // Kumpulkan semua dimensi yang diisi
    const dimensi = Array.from(dimensiInputs)
                         .map(input => input.value.trim())
                         .filter(value => value !== '');

    // Asumsikan untuk metriks kita masih pakai 1 input
    const metriksValue = document.getElementById("metrik-input").value.trim();
    
    // Pastikan user sudah pilih tabel
    if (!selectedTable) {
        alert('Silakan pilih tabel terlebih dahulu!');
        return;
    }

    // Contoh validasi sederhana
    if (dimensi.length === 0) {
        alert('Minimal satu dimensi harus diisi!');
        return;
    }

    // Lakukan request
    axios.post(`/api/kelola-dashboard/table-data/${selectedTable}`, {
        dimensi: dimensi,      // <- array
        metriks: metriksValue  // <- string (jika hanya 1 metriks)
    })
    .then(response => {
        if (response.data.success) {
            // Perubahan: gunakan response.query untuk membangun tabel
            displayDataInCanvas(response.data.data, response.data.query, metriksValue);
        } else {
            alert(response.data.message || 'Terjadi kesalahan.');
        }
    })
    .catch(error => {
        console.error(error);
        alert('Gagal memuat data.');
    });
}


  function fetchTableData(table, columns) {
    axios.post(`/api/kelola-dashboard/table-data/${table}`, {
      columns: columns
    })
    .then(response => {
      if (response.data.success) {
        // Perubahan: gunakan response.query untuk membangun tabel
        displayDataInCanvas(response.data.data, response.data.query);
      } else {
        alert('Gagal mengambil data.');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Terjadi kesalahan saat mengambil data.');
    });
  }

  function displayDataInCanvas(data, query, metriksValue) {
    const tableContainer = document.getElementById("tableContainer");

    // Pastikan DataTables hanya di-inisialisasi sekali
    if ($.fn.dataTable.isDataTable('#dataTable')) {
      $('#dataTable').DataTable().clear().destroy();
    }

    const selectMatch = query.match(/SELECT\s+(.+?)\s+FROM/i);
    if (!selectMatch || selectMatch.length < 2) {
      console.error("Query tidak memiliki format SELECT ... FROM yang valid.");
      tableContainer.innerHTML = "<p>Format query tidak valid.</p>";
      return;
    }
    const columnsPart = selectMatch[1];

    // Split kolom berdasarkan koma dan hapus spasi
    const columnsArr = columnsPart.split(',').map(col => col.trim()).map(col => {
        let aliasMatch = col.match(/as\s+(.+)$/i);
        if (aliasMatch && aliasMatch[1]) {
            return aliasMatch[1].trim();
        }
        return col;
    });

    // Cek apakah ada kolom 'metrics' dan urutkan berdasarkan nilai total secara descending
    if (columnsArr.includes('metrics')) {
        data.sort((b, a) => a['metrics'] - b['metrics']); // Urutkan secara descending
    }

    // Hapus kolom "metrics" jika metriks kosong
    if (!metriksValue) {
        const index = columnsArr.indexOf('metrics');
        if (index > -2) {
            columnsArr.splice(index, 1);
        }
    }

    // Bangun header dan body tabel berdasarkan kolom yang didapat dari query
    let content = `
      <table id="dataTable" class="table display responsive">
        <thead>
          <tr>
            ${columnsArr.map(col => `<th>${col}</th>`).join('')}
          </tr>
        </thead>
        <tbody>
    `;

    // Iterasi tiap baris data, tampilkan nilai sesuai urutan kolom
    data.forEach(row => {
      content += "<tr>";
      columnsArr.forEach(col => {
        content += `<td>${row[col]}</td>`;
      });
      content += "</tr>";
    });
    content += "</tbody></table>";
    tableContainer.innerHTML = content;

    // Inisialisasi DataTables
    $('#dataTable').DataTable({
      responsive: true,
      // (Opsional) Konfigurasi pengurutan bisa ditambahkan di sini jika diperlukan
    });

    
}

        // Jalankan setelah DOM siap
        document.addEventListener("DOMContentLoaded", function() {
    const tableAccordion = document.getElementById("tableAccordion");
    const loading = document.getElementById("loading");

    axios.get("/api/kelola-dashboard/tables")
      .then(response => {
        const tables = response.data.data;
        if (tables.length === 0) {
          tableAccordion.innerHTML = "<p class='text-muted'>Tidak ada tabel tersedia.</p>";
          loading.style.display = "none";
          return;
        }

        tables.forEach((table, index) => {
          const accordionItem = document.createElement("div");
          accordionItem.classList.add("accordion-item");
          accordionItem.innerHTML = `
            <h2 class="accordion-header" id="heading-${index}">
              <button class="accordion-button collapsed" 
                      type="button" 
                      data-bs-toggle="collapse" 
                      data-bs-target="#collapse-${index}" 
                      aria-expanded="false" 
                      aria-controls="collapse-${index}">
                ${table}
              </button>
            </h2>
            <div id="collapse-${index}" 
                 class="accordion-collapse collapse" 
                 aria-labelledby="heading-${index}" 
                 data-bs-parent="#tableAccordion">
              <div class="column-container" id="columns-${table}">
                <p class='text-muted'>Loading...</p>
              </div>
            </div>
          `;

          // Event ketika accordion tombol diklik, set selectedTable
          const accordionButton = accordionItem.querySelector('.accordion-button');
          accordionButton.addEventListener('click', function() {
            selectedTable = table;
          });

          tableAccordion.appendChild(accordionItem);

          // Ambil kolom-kolom dari tabel
          fetchColumns(table);
        });
        loading.style.display = "none";
      })
      .catch(error => {
        tableAccordion.innerHTML = `<p class='text-danger'>Gagal mengambil data tabel.</p>`;
        console.error(error);
        loading.style.display = "none";
      });

    // Fungsi ambil kolom
    function fetchColumns(table) {
      axios.get(`/api/kelola-dashboard/columns/${table}`)
        .then(response => {
          const columns = response.data.data;
          const columnContainer = document.getElementById(`columns-${table}`);
          columnContainer.innerHTML = "";

          columns.forEach(col => {
            const columnCard = document.createElement("div");
            columnCard.classList.add("column-card");
            columnCard.draggable = true;
            // Simpan data kolom (misal, gunakan id atau nama sesuai kebutuhan)
            columnCard.dataset.column = col.name;

            let icon = '';
            if (col.type.includes('int') || 
                col.type.includes('numeric') || 
                col.type.includes('float') || 
                col.type.includes('double') || 
                col.type.includes('decimal')) {
              icon = '<span class="column-icons">123</span>';
            } else if (col.type.includes('char') || 
                       col.type.includes('text') || 
                       col.type.includes('string')) {
              icon = '<span class="column-icons">ABC</span>';
            } else if (col.type.includes('date') || 
                       col.type.includes('time') || 
                       col.type.includes('timestamp')) {
              icon = '<span class="column-icons">DATE</span>';
            } else {
              icon = '<i class="fas fa-database column-icons"></i>';
            }

            columnCard.innerHTML = `
              ${icon} ${col.name}
            `;

            columnCard.addEventListener("dragstart", event => {
              event.dataTransfer.setData("text/plain", col.name);
            });

            columnContainer.appendChild(columnCard);
          });
        })
        .catch(error => {
          console.error(`Gagal mengambil kolom untuk tabel ${table}:`, error);
        });
    }
  });
      </script>
    
      <script>
        // Script untuk menampilkan/menyembunyikan sidebar Data vs Diagram
        document.addEventListener("DOMContentLoaded", function() {
          const sidebarData = document.getElementById("sidebar-data");
          const sidebarDiagram = document.getElementById("sidebar-diagram");
    
          // Pastikan hanya sidebar-data yang muncul pertama kali
          sidebarData.style.display = "block";
          sidebarDiagram.style.display = "none";
    
          // Tangkap tombol Pilih Data dan Pilih Visualisasi
          const pilihDataBtn = document.getElementById("menu-data");
          const pilihVisualisasiBtn = document.getElementById("menu-visualisasi");
    
          if (pilihDataBtn && pilihVisualisasiBtn) {
              pilihDataBtn.addEventListener("click", function() {
                  sidebarData.style.display = "block";
                  sidebarDiagram.style.display = "none";
              });
    
              pilihVisualisasiBtn.addEventListener("click", function() {
                  sidebarData.style.display = "none";
                  sidebarDiagram.style.display = "block";
              });
          }
        });
      </script>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>