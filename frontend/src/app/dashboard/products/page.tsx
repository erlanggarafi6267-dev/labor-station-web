"use client";

import {
  FormEvent,
  useCallback,
  useEffect,
  useState,
} from "react";

const API_URL = (
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000/api"
).replace(/\/$/, "");

type Category = {
  id: number;
  name: string;
  is_active: boolean;
};

type ProductPrice = {
  id?: number;
  sales_channel: "OFFLINE" | "GRABFOOD";
  price: number | string;
  is_active: boolean;
};

type Product = {
  id: number;
  category_id: number;
  name: string;
  slug: string;
  sku: string | null;
  description: string | null;
  image_path: string | null;
  temperature_options: string[] | null;
  offline_status: "AVAILABLE" | "UNAVAILABLE";
  grabfood_status: "AVAILABLE" | "UNAVAILABLE";
  is_active: boolean;
  category?: Category;
  prices: ProductPrice[];
};

type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

type ProductForm = {
  category_id: string;
  name: string;
  sku: string;
  description: string;
  temperature_options: string[];
  offline_status: "AVAILABLE" | "UNAVAILABLE";
  grabfood_status: "AVAILABLE" | "UNAVAILABLE";
  offline_price: string;
  grabfood_price: string;
};

const emptyForm: ProductForm = {
  category_id: "",
  name: "",
  sku: "",
  description: "",
  temperature_options: [],
  offline_status: "AVAILABLE",
  grabfood_status: "UNAVAILABLE",
  offline_price: "",
  grabfood_price: "",
};

function formatRupiah(value: number | string) {
  return new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);
}

function getErrorMessage(
  result: {
    message?: string;
    errors?: Record<string, string[]>;
  },
  fallback: string
) {
  if (result.errors) {
    const firstError = Object.values(result.errors)[0]?.[0];

    if (firstError) return firstError;
  }

  return result.message || fallback;
}

function getPrice(
  product: Product,
  channel: "OFFLINE" | "GRABFOOD"
) {
  const price = product.prices?.find(
    (item) => item.sales_channel === channel
  );

  return price ? Number(price.price) : 0;
}

function getStatusLabel(status: string) {
  return status === "AVAILABLE" ? "Tersedia" : "Tidak tersedia";
}

export default function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);

  const [pagination, setPagination] =
    useState<Paginated<Product> | null>(null);

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("active");
  const [page, setPage] = useState(1);

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<ProductForm>(emptyForm);

  const [userName, setUserName] = useState("Owner");
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const token = () =>
    typeof window !== "undefined"
      ? localStorage.getItem("labor_station_token")
      : null;

  // =====================================================
  // API REQUEST
  // =====================================================

  const request = useCallback(
    async (path: string, options: RequestInit = {}) => {
      const currentToken = localStorage.getItem(
        "labor_station_token"
      );

      if (!currentToken) {
        window.location.replace("/");
        throw new Error("Sesi login tidak ditemukan.");
      }

      const response = await fetch(`${API_URL}${path}`, {
        ...options,
        headers: {
          Accept: "application/json",
          ...(options.body
            ? { "Content-Type": "application/json" }
            : {}),
          Authorization: `Bearer ${currentToken}`,
          ...options.headers,
        },
        cache: "no-store",
      });

      if (response.status === 401) {
        localStorage.removeItem("labor_station_token");
        localStorage.removeItem("labor_station_user");

        window.location.replace("/");
        throw new Error("Sesi login telah berakhir.");
      }

      const result = await response.json().catch(() => ({}));

      if (!response.ok) {
        throw new Error(
          getErrorMessage(
            result,
            "Permintaan ke server gagal."
          )
        );
      }

      return result;
    },
    []
  );

  // =====================================================
  // LOAD KATEGORI
  // =====================================================

  const fetchCategories = useCallback(async () => {
    const result = await request("/owner/categories");

    const categoryData =
      result.data?.data ??
      result.data ??
      [];

    setCategories(
      Array.isArray(categoryData)
        ? categoryData.filter(
            (item: Category) => item.is_active !== false
          )
        : []
    );
  }, [request]);

  // =====================================================
  // LOAD PRODUK
  // =====================================================

  const fetchProducts = useCallback(async () => {
    const params = new URLSearchParams({
      search,
      status: statusFilter,
      per_page: "10",
      page: String(page),
    });

    if (categoryFilter !== "all") {
      params.set("category_id", categoryFilter);
    }

    const result = await request(
      `/owner/products?${params.toString()}`
    );

    setProducts(result.data.data || []);
    setPagination(result.data);
  }, [
    request,
    search,
    categoryFilter,
    statusFilter,
    page,
  ]);

  const refreshData = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      await Promise.all([
        fetchProducts(),
        fetchCategories(),
      ]);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal memuat data produk."
      );
    } finally {
      setLoading(false);
    }
  }, [fetchProducts, fetchCategories]);

  // =====================================================
  // AUTHENTICATION
  // =====================================================

  useEffect(() => {
    const savedToken = token();
    const savedUser = localStorage.getItem(
      "labor_station_user"
    );

    if (!savedToken || !savedUser) {
      window.location.replace("/");
      return;
    }

    try {
      const user = JSON.parse(savedUser);

      if (user.role !== "owner") {
        window.location.replace("/");
        return;
      }

      setUserName(user.name || "Owner");
    } catch {
      localStorage.removeItem("labor_station_user");
      window.location.replace("/");
    }
  }, []);

  useEffect(() => {
    if (token()) {
      refreshData();
    }
  }, [refreshData]);

  // =====================================================
  // FORM PRODUK
  // =====================================================

  function resetForm() {
    setForm(emptyForm);
    setEditingId(null);
    setModalOpen(false);
  }

  function openCreateProduct() {
    setForm({
      ...emptyForm,
      category_id: categories[0]
        ? String(categories[0].id)
        : "",
    });

    setEditingId(null);
    setModalOpen(true);
    setError("");
    setSuccess("");
  }

  function openEditProduct(product: Product) {
    setEditingId(product.id);

    setForm({
      category_id: String(product.category_id),
      name: product.name,
      sku: product.sku || "",
      description: product.description || "",
      temperature_options:
        product.temperature_options || [],
      offline_status: product.offline_status,
      grabfood_status: product.grabfood_status,
      offline_price: String(
        getPrice(product, "OFFLINE") || ""
      ),
      grabfood_price: String(
        getPrice(product, "GRABFOOD") || ""
      ),
    });

    setModalOpen(true);
    setError("");
    setSuccess("");
  }

  function updateForm<K extends keyof ProductForm>(
    key: K,
    value: ProductForm[K]
  ) {
    setForm((previous) => ({
      ...previous,
      [key]: value,
    }));
  }

  function toggleTemperature(value: string) {
    setForm((previous) => {
      const exists =
        previous.temperature_options.includes(value);

      return {
        ...previous,
        temperature_options: exists
          ? previous.temperature_options.filter(
              (item) => item !== value
            )
          : [...previous.temperature_options, value],
      };
    });
  }

  // =====================================================
  // SIMPAN PRODUK
  // =====================================================

  async function saveProduct(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault();

    setSaving(true);
    setError("");
    setSuccess("");

    try {
      if (
        form.offline_status === "AVAILABLE" &&
        Number(form.offline_price) <= 0
      ) {
        throw new Error(
          "Harga OFFLINE wajib diisi jika channel tersedia."
        );
      }

      if (
        form.grabfood_status === "AVAILABLE" &&
        Number(form.grabfood_price) <= 0
      ) {
        throw new Error(
          "Harga GRABFOOD wajib diisi jika channel tersedia."
        );
      }

      const prices: ProductPrice[] = [];

      if (Number(form.offline_price) > 0) {
        prices.push({
          sales_channel: "OFFLINE",
          price: Number(form.offline_price),
          is_active: form.offline_status === "AVAILABLE",
        });
      }

      if (Number(form.grabfood_price) > 0) {
        prices.push({
          sales_channel: "GRABFOOD",
          price: Number(form.grabfood_price),
          is_active: form.grabfood_status === "AVAILABLE",
        });
      }

      const payload = {
        category_id: Number(form.category_id),
        name: form.name.trim(),
        sku: form.sku.trim() || null,
        description: form.description.trim() || null,
        temperature_options:
          form.temperature_options.length > 0
            ? form.temperature_options
            : null,
        offline_status: form.offline_status,
        grabfood_status: form.grabfood_status,
        prices,
      };

      await request(
        editingId === null
          ? "/owner/products"
          : `/owner/products/${editingId}`,
        {
          method: editingId === null ? "POST" : "PUT",
          body: JSON.stringify(payload),
        }
      );

      setSuccess(
        editingId === null
          ? "Produk berhasil ditambahkan."
          : "Produk berhasil diperbarui."
      );

      resetForm();
      await refreshData();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal menyimpan produk."
      );
    } finally {
      setSaving(false);
    }
  }

  // =====================================================
  // HAPUS / ARSIP PRODUK
  // =====================================================

  async function deleteProduct(product: Product) {
    const confirmed = window.confirm(
      `Arsipkan produk "${product.name}"?\n\nProduk akan dinonaktifkan dan tidak dihapus dari riwayat transaksi.`
    );

    if (!confirmed) return;

    setError("");
    setSuccess("");

    try {
      await request(`/owner/products/${product.id}`, {
        method: "DELETE",
      });

      setSuccess(
        `Produk "${product.name}" berhasil diarsipkan.`
      );

      await refreshData();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal mengarsipkan produk."
      );
    }
  }

  // =====================================================
  // LOGOUT
  // =====================================================

  function handleLogout() {
    localStorage.removeItem("labor_station_token");
    localStorage.removeItem("labor_station_user");
    window.location.replace("/");
  }

  // =====================================================
  // TAMPILAN
  // =====================================================

  return (
    <main className="min-h-screen bg-[#f5eee6] text-[#392619]">

      {/* SIDEBAR */}

      <aside className="fixed inset-y-0 left-0 hidden w-[250px] flex-col bg-[#302016] text-[#fff5e9] lg:flex">
        <div className="border-b border-white/10 px-6 py-7">
          <h1 className="text-lg font-black tracking-wide">
            LABOR_STATION
          </h1>

          <p className="mt-1 text-[10px] tracking-[0.2em] text-[#d8bfa5]">
            MANAGEMENT SYSTEM
          </p>
        </div>

        <nav className="space-y-1 px-4 py-6">
          {[
            ["Dashboard", "/dashboard"],
            ["Transaksi", "/dashboard/transactions"],
            ["Riwayat Transaksi", "/dashboard/history"],
            ["Produk", "/dashboard/products"],
            ["Inventori", "/dashboard/inventory"],
            ["Pengeluaran", "/dashboard/expenses"],
            ["Laporan", "/dashboard/reports"],
            ["Audit Log", "/dashboard/activity"],
          ].map(([label, href]) => (
            <a
              key={href}
              href={href}
              className={`block rounded-xl px-4 py-3 text-sm transition ${
                href === "/dashboard/products"
                  ? "bg-[#e6c5a3] font-bold text-[#392619]"
                  : "text-[#ead9c7] hover:bg-white/10"
              }`}
            >
              {label}
            </a>
          ))}
        </nav>

        <div className="mt-auto border-t border-white/10 p-5">
          <p className="truncate text-sm font-bold">
            {userName}
          </p>

          <p className="mt-1 text-xs text-[#d8bfa5]">
            Owner Account
          </p>

          <button
            onClick={handleLogout}
            className="mt-5 w-full rounded-xl px-4 py-3 text-left text-sm text-[#f2c6b8] hover:bg-red-500/10"
          >
            Sign Out
          </button>
        </div>
      </aside>

      {/* KONTEN UTAMA */}

      <div className="lg:ml-[250px]">

        {/* HEADER */}

        <header className="flex h-[76px] items-center justify-between border-b border-[#e8d9ca] bg-[#fffaf4] px-5 sm:px-8">
          <div>
            <p className="text-xs text-[#92775f]">
              Workspace / Management
            </p>

            <h2 className="mt-1 font-extrabold">
              Manajemen Produk
            </h2>
          </div>

          <button
            onClick={refreshData}
            disabled={loading}
            className="rounded-xl bg-[#543923] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#69452d] disabled:opacity-50"
          >
            Refresh
          </button>
        </header>

        <div className="mx-auto max-w-[1450px] space-y-7 p-4 sm:p-7 lg:p-9">

          {/* JUDUL */}

          <section className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9b795b]">
                Product Management
              </p>

              <h1 className="mt-2 text-2xl font-black sm:text-3xl">
                Katalog Produk
              </h1>

              <p className="mt-2 text-sm text-[#92775f]">
                Kelola produk, kategori, harga penjualan, dan ketersediaan channel.
              </p>
            </div>

            <button
              onClick={openCreateProduct}
              disabled={categories.length === 0}
              className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white hover:bg-[#69452d] disabled:cursor-not-allowed disabled:opacity-50"
            >
              + Tambah Produk
            </button>
          </section>

          {/* ERROR */}

          {error && (
            <div
              role="alert"
              className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700"
            >
              {error}
            </div>
          )}

          {/* SUCCESS */}

          {success && (
            <div
              role="status"
              className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
            >
              {success}
            </div>
          )}

          {/* RINGKASAN */}

          <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {[
              {
                label: "Produk Ditampilkan",
                value: pagination?.total ?? products.length,
              },
              {
                label: "Produk Aktif",
                value: products.filter(
                  (item) => item.is_active
                ).length,
              },
              {
                label: "Tersedia OFFLINE",
                value: products.filter(
                  (item) =>
                    item.offline_status === "AVAILABLE"
                ).length,
              },
              {
                label: "Tersedia GRABFOOD",
                value: products.filter(
                  (item) =>
                    item.grabfood_status === "AVAILABLE"
                ).length,
              },
            ].map((item) => (
              <div
                key={item.label}
                className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm"
              >
                <p className="text-xs font-medium text-[#92775f]">
                  {item.label}
                </p>

                <p className="mt-3 text-3xl font-black text-[#543923]">
                  {item.value}
                </p>
              </div>
            ))}
          </section>

          {/* TABEL PRODUK */}

          <section className="overflow-hidden rounded-2xl border border-[#eadccf] bg-[#fffaf4] shadow-sm">

            {/* FILTER */}

            <div className="flex flex-col gap-3 border-b border-[#eadccf] p-5 lg:flex-row">
              <input
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setPage(1);
                }}
                placeholder="Cari nama produk atau SKU..."
                className="min-w-0 flex-1 rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
              />

              <select
                value={categoryFilter}
                onChange={(event) => {
                  setCategoryFilter(event.target.value);
                  setPage(1);
                }}
                className="rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
              >
                <option value="all">Semua Kategori</option>

                {categories.map((category) => (
                  <option
                    key={category.id}
                    value={category.id}
                  >
                    {category.name}
                  </option>
                ))}
              </select>

              <select
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(event.target.value);
                  setPage(1);
                }}
                className="rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
              >
                <option value="active">Produk Aktif</option>
                <option value="inactive">Produk Nonaktif</option>
                <option value="all">Semua Status</option>
              </select>
            </div>

            {/* TABEL */}

            <div className="overflow-x-auto">
              <table className="w-full min-w-[1100px] text-left text-sm">
                <thead className="bg-[#f5eee6] text-xs uppercase tracking-wide text-[#80664e]">
                  <tr>
                    <th className="px-5 py-4">Produk</th>
                    <th className="px-5 py-4">Kategori</th>
                    <th className="px-5 py-4">OFFLINE</th>
                    <th className="px-5 py-4">GRABFOOD</th>
                    <th className="px-5 py-4">Suhu</th>
                    <th className="px-5 py-4">Status</th>
                    <th className="px-5 py-4 text-right">Aksi</th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-[#eee2d7]">
                  {loading ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-5 py-12 text-center text-[#92775f]"
                      >
                        Memuat data produk...
                      </td>
                    </tr>
                  ) : products.length === 0 ? (
                    <tr>
                      <td
                        colSpan={7}
                        className="px-5 py-12 text-center text-[#92775f]"
                      >
                        Belum ada produk yang ditemukan.
                      </td>
                    </tr>
                  ) : (
                    products.map((product) => (
                      <tr
                        key={product.id}
                        className="hover:bg-[#fcf5ed]"
                      >
                        <td className="px-5 py-4">
                          <p className="font-bold text-[#392619]">
                            {product.name}
                          </p>

                          <p className="mt-1 text-xs text-[#92775f]">
                            SKU: {product.sku || "-"}
                          </p>
                        </td>

                        <td className="px-5 py-4">
                          {product.category?.name || "-"}
                        </td>

                        <td className="px-5 py-4">
                          <p className="font-semibold">
                            {formatRupiah(
                              getPrice(product, "OFFLINE")
                            )}
                          </p>

                          <span
                            className={`mt-1 inline-block rounded-full px-2 py-1 text-[10px] font-bold ${
                              product.offline_status === "AVAILABLE"
                                ? "bg-emerald-100 text-emerald-700"
                                : "bg-gray-100 text-gray-600"
                            }`}
                          >
                            {getStatusLabel(product.offline_status)}
                          </span>
                        </td>

                        <td className="px-5 py-4">
                          <p className="font-semibold">
                            {formatRupiah(
                              getPrice(product, "GRABFOOD")
                            )}
                          </p>

                          <span
                            className={`mt-1 inline-block rounded-full px-2 py-1 text-[10px] font-bold ${
                              product.grabfood_status === "AVAILABLE"
                                ? "bg-emerald-100 text-emerald-700"
                                : "bg-gray-100 text-gray-600"
                            }`}
                          >
                            {getStatusLabel(product.grabfood_status)}
                          </span>
                        </td>

                        <td className="px-5 py-4">
                          {product.temperature_options?.length
                            ? product.temperature_options.join(" / ")
                            : "-"}
                        </td>

                        <td className="px-5 py-4">
                          <span
                            className={`rounded-full px-3 py-1 text-xs font-bold ${
                              product.is_active
                                ? "bg-emerald-100 text-emerald-700"
                                : "bg-gray-100 text-gray-600"
                            }`}
                          >
                            {product.is_active ? "Aktif" : "Nonaktif"}
                          </span>
                        </td>

                        <td className="px-5 py-4">
                          <div className="flex justify-end gap-2">
                            <button
                              onClick={() => openEditProduct(product)}
                              className="rounded-lg border border-[#ddc9b5] px-3 py-2 text-xs font-bold text-[#543923] hover:bg-[#f5eee6]"
                            >
                              Edit
                            </button>

                            {product.is_active && (
                              <button
                                onClick={() => deleteProduct(product)}
                                className="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-50"
                              >
                                Arsipkan
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/* PAGINATION */}

            <div className="flex flex-col justify-between gap-3 border-t border-[#eadccf] p-5 sm:flex-row sm:items-center">
              <p className="text-xs text-[#92775f]">
                Halaman {pagination?.current_page ?? 1} dari{" "}
                {pagination?.last_page ?? 1} · Total{" "}
                {pagination?.total ?? 0} produk
              </p>

              <div className="flex gap-2">
                <button
                  onClick={() =>
                    setPage((previous) => Math.max(1, previous - 1))
                  }
                  disabled={!pagination || page <= 1 || loading}
                  className="rounded-lg border border-[#ddc9b5] px-4 py-2 text-xs font-bold disabled:opacity-40"
                >
                  Sebelumnya
                </button>

                <button
                  onClick={() =>
                    setPage((previous) =>
                      Math.min(
                        pagination?.last_page ?? previous,
                        previous + 1
                      )
                    )
                  }
                  disabled={
                    !pagination ||
                    page >= pagination.last_page ||
                    loading
                  }
                  className="rounded-lg border border-[#ddc9b5] px-4 py-2 text-xs font-bold disabled:opacity-40"
                >
                  Berikutnya
                </button>
              </div>
            </div>
          </section>
        </div>
      </div>

      {/* MODAL TAMBAH / EDIT PRODUK */}

      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4">
          <div className="my-6 w-full max-w-2xl rounded-2xl bg-[#fffaf4] shadow-2xl">

            <div className="flex items-center justify-between border-b border-[#eadccf] px-6 py-5">
              <div>
                <h2 className="text-xl font-black">
                  {editingId === null
                    ? "Tambah Produk"
                    : "Edit Produk"}
                </h2>

                <p className="mt-1 text-xs text-[#92775f]">
                  Lengkapi informasi dan harga produk.
                </p>
              </div>

              <button
                onClick={resetForm}
                className="rounded-lg px-3 py-2 text-xl text-[#92775f] hover:bg-[#f5eee6]"
              >
                ×
              </button>
            </div>

            <form
              onSubmit={saveProduct}
              className="max-h-[75vh] space-y-5 overflow-y-auto p-6"
            >

              {/* INFORMASI PRODUK */}

              <section className="space-y-4">
                <h3 className="text-sm font-black uppercase tracking-wide text-[#80664e]">
                  Informasi Produk
                </h3>

                <div>
                  <label className="mb-2 block text-sm font-semibold">
                    Nama Produk *
                  </label>

                  <input
                    required
                    maxLength={150}
                    value={form.name}
                    onChange={(event) =>
                      updateForm("name", event.target.value)
                    }
                    placeholder="Contoh: Coffee Latte Aren"
                    className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                  />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      Kategori *
                    </label>

                    <select
                      required
                      value={form.category_id}
                      onChange={(event) =>
                        updateForm("category_id", event.target.value)
                      }
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    >
                      <option value="">Pilih kategori</option>

                      {categories.map((category) => (
                        <option
                          key={category.id}
                          value={category.id}
                        >
                          {category.name}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      SKU
                    </label>

                    <input
                      maxLength={50}
                      value={form.sku}
                      onChange={(event) =>
                        updateForm("sku", event.target.value)
                      }
                      placeholder="Opsional"
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    />
                  </div>
                </div>

                <div>
                  <label className="mb-2 block text-sm font-semibold">
                    Deskripsi
                  </label>

                  <textarea
                    rows={3}
                    value={form.description}
                    onChange={(event) =>
                      updateForm("description", event.target.value)
                    }
                    placeholder="Deskripsi produk (opsional)"
                    className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                  />
                </div>
              </section>

              <div className="border-t border-[#eadccf]" />

              {/* PILIHAN SUHU */}

              <section className="space-y-3">
                <div>
                  <h3 className="text-sm font-black uppercase tracking-wide text-[#80664e]">
                    Pilihan Suhu Minuman
                  </h3>

                  <p className="mt-1 text-xs text-[#92775f]">
                    Berlaku untuk produk minuman yang dapat disajikan panas atau dingin.
                  </p>
                </div>

                <div className="flex flex-wrap gap-3">
                  {["HOT", "ICED"].map((temperature) => (
                    <label
                      key={temperature}
                      className={`flex cursor-pointer items-center gap-2 rounded-xl border px-5 py-3 text-sm font-bold ${
                        form.temperature_options.includes(temperature)
                          ? "border-[#8b674d] bg-[#f5eee6] text-[#543923]"
                          : "border-[#ddc9b5] bg-white"
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={form.temperature_options.includes(
                          temperature
                        )}
                        onChange={() =>
                          toggleTemperature(temperature)
                        }
                        className="accent-[#543923]"
                      />

                      {temperature}
                    </label>
                  ))}
                </div>
              </section>

              <div className="border-t border-[#eadccf]" />

              {/* HARGA OFFLINE */}

              <section className="space-y-3">
                <h3 className="text-sm font-black uppercase tracking-wide text-[#80664e]">
                  Penjualan OFFLINE
                </h3>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      Status
                    </label>

                    <select
                      value={form.offline_status}
                      onChange={(event) =>
                        updateForm(
                          "offline_status",
                          event.target.value as ProductForm["offline_status"]
                        )
                      }
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    >
                      <option value="AVAILABLE">Tersedia</option>
                      <option value="UNAVAILABLE">
                        Tidak tersedia
                      </option>
                    </select>
                  </div>

                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      Harga OFFLINE (Rp)
                    </label>

                    <input
                      type="number"
                      min="0"
                      value={form.offline_price}
                      onChange={(event) =>
                        updateForm("offline_price", event.target.value)
                      }
                      placeholder="Contoh: 12000"
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    />
                  </div>
                </div>
              </section>

              <div className="border-t border-[#eadccf]" />

              {/* HARGA GRABFOOD */}

              <section className="space-y-3">
                <h3 className="text-sm font-black uppercase tracking-wide text-[#80664e]">
                  Penjualan GRABFOOD
                </h3>

                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      Status
                    </label>

                    <select
                      value={form.grabfood_status}
                      onChange={(event) =>
                        updateForm(
                          "grabfood_status",
                          event.target.value as ProductForm["grabfood_status"]
                        )
                      }
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    >
                      <option value="AVAILABLE">Tersedia</option>
                      <option value="UNAVAILABLE">
                        Tidak tersedia
                      </option>
                    </select>
                  </div>

                  <div>
                    <label className="mb-2 block text-sm font-semibold">
                      Harga GRABFOOD (Rp)
                    </label>

                    <input
                      type="number"
                      min="0"
                      value={form.grabfood_price}
                      onChange={(event) =>
                        updateForm("grabfood_price", event.target.value)
                      }
                      placeholder="Contoh: 15000"
                      className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                    />
                  </div>
                </div>
              </section>

              <div className="rounded-xl bg-[#f5eee6] p-4 text-xs leading-relaxed text-[#80664e]">
                <strong>Catatan:</strong> Setiap channel yang berstatus
                Tersedia wajib memiliki harga. Harga OFFLINE dan
                GRABFOOD dapat berbeda. Mengubah harga satu channel
                tidak mengubah harga channel lainnya.
              </div>

              {/* TOMBOL */}

              <div className="flex flex-col-reverse gap-3 border-t border-[#eadccf] pt-5 sm:flex-row sm:justify-end">
                <button
                  type="button"
                  onClick={resetForm}
                  disabled={saving}
                  className="rounded-xl border border-[#ddc9b5] px-5 py-3 text-sm font-bold text-[#543923]"
                >
                  Batal
                </button>

                <button
                  type="submit"
                  disabled={saving || categories.length === 0}
                  className="rounded-xl bg-[#543923] px-6 py-3 text-sm font-bold text-white hover:bg-[#69452d] disabled:opacity-50"
                >
                  {saving
                    ? "Menyimpan..."
                    : editingId === null
                      ? "Simpan Produk"
                      : "Simpan Perubahan"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </main>
  );
}