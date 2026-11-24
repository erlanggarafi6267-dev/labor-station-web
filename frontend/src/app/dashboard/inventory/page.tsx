"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { apiRequest } from "@/lib/api";

type Ingredient = {
  id: number;
  name: string;
  unit: string;
  current_stock: string | number;
  minimum_stock: string | number;
  is_active: boolean;
};

type Movement = {
  id: number;
  type: "IN" | "OUT" | "ADJUSTMENT";
  quantity: string | number;
  stock_before: string | number;
  stock_after: string | number;
  reason: string;
  movement_at: string;
  ingredient: {
    id: number;
    name: string;
    unit: string;
  };
  user: {
    id: number;
    name: string;
  };
};

type Summary = {
  total: number;
  low_stock: number;
  out_of_stock: number;
  inactive: number;
};

type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

type IngredientForm = {
  name: string;
  unit: string;
  current_stock: string;
  minimum_stock: string;
};

type MovementForm = {
  type: "IN" | "OUT" | "ADJUSTMENT";
  quantity: string;
  reason: string;
};

const emptyIngredientForm: IngredientForm = {
  name: "",
  unit: "",
  current_stock: "0",
  minimum_stock: "0",
};

const emptyMovementForm: MovementForm = {
  type: "IN",
  quantity: "",
  reason: "",
};

function formatNumber(value: number | string) {
  return new Intl.NumberFormat("id-ID", {
    maximumFractionDigits: 3,
  }).format(Number(value) || 0);
}

function formatDate(value: string) {
  return new Date(value).toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function getErrorMessage(
  result: { message?: string; errors?: Record<string, string[]> },
  fallback: string
) {
  if (result.errors) {
    const firstError = Object.values(result.errors)[0]?.[0];

    if (firstError) return firstError;
  }

  return result.message || fallback;
}

export default function InventoryPage() {
  const [ingredients, setIngredients] = useState<Ingredient[]>([]);
  const [movements, setMovements] = useState<Movement[]>([]);

  const [ingredientModalOpen, setIngredientModalOpen] = useState(false);

  const [summary, setSummary] = useState<Summary>({
    total: 0,
    low_stock: 0,
    out_of_stock: 0,
    inactive: 0,
  });

  const [ingredientForm, setIngredientForm] =
    useState<IngredientForm>(emptyIngredientForm);

  const [movementForm, setMovementForm] =
    useState<MovementForm>(emptyMovementForm);

  const [editingId, setEditingId] = useState<number | null>(null);

  const [selectedIngredient, setSelectedIngredient] =
    useState<Ingredient | null>(null);

  const [search, setSearch] = useState("");
  const [stockFilter, setStockFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("active");

  const [activeTab, setActiveTab] = useState<
    "ingredients" | "movements"
  >("ingredients");

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const [ingredientPage, setIngredientPage] = useState(1);
  const [movementPage, setMovementPage] = useState(1);

  const [ingredientPagination, setIngredientPagination] =
    useState<Paginated<Ingredient> | null>(null);

  const [movementPagination, setMovementPagination] =
    useState<Paginated<Movement> | null>(null);

  const [userName, setUserName] = useState("Owner");
  const [isAuthenticated, setIsAuthenticated] = useState(false);

  const [movementError, setMovementError] = useState("");

  // =====================================================
  // API REQUEST
  // =====================================================

  const request = useCallback(
    async (path: string, options: RequestInit = {}) => {
      const response = await apiRequest(path, options);

      if (response.status === 401) {
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
  // FETCH DATA INVENTARIS
  // =====================================================

  const fetchIngredients = useCallback(async () => {
    const params = new URLSearchParams({
      search,
      status: statusFilter,
      stock: stockFilter,
      per_page: "10",
      page: String(ingredientPage),
    });

    const result = await request(
      `/inventory?${params.toString()}`
    );

    setIngredients(result.data.data);
    setIngredientPagination(result.data);
    setSummary(result.summary);
  }, [
    request,
    search,
    statusFilter,
    stockFilter,
    ingredientPage,
  ]);

  const fetchMovements = useCallback(async () => {
    const params = new URLSearchParams({
      per_page: "10",
      page: String(movementPage),
    });

    const result = await request(
      `/inventory/movements?${params.toString()}`
    );

    setMovements(result.data.data);
    setMovementPagination(result.data);
  }, [request, movementPage]);

  const refreshData = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      await Promise.all([
        fetchIngredients(),
        fetchMovements(),
      ]);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal memuat data inventaris."
      );
    } finally {
      setLoading(false);
    }
  }, [fetchIngredients, fetchMovements]);

  // =====================================================
  // AUTHENTICATION - SESSION COOKIE
  // =====================================================

  useEffect(() => {
    let active = true;

    async function checkSession() {
      try {
        const response = await apiRequest("/me");

        if (!response.ok) {
          window.location.replace("/");
          return;
        }

        const result = await response.json();
        const user = result.user ?? result;

        if (!user?.id || user.role !== "owner") {
          window.location.replace("/");
          return;
        }

        if (active) {
          setUserName(user.name || "Owner");
          setIsAuthenticated(true);
        }
      } catch (err) {
        console.error("Gagal memverifikasi sesi:", err);

        if (active) {
          window.location.replace("/");
        }
      }
    }

    checkSession();

    return () => {
      active = false;
    };
  }, []);

  // =====================================================
  // FETCH DATA SETELAH LOGIN TERVERIFIKASI
  // =====================================================


  useEffect(() => {
    if (!isAuthenticated) return;

    queueMicrotask(() => {
        void refreshData();
    });
  }, [isAuthenticated, refreshData]);


  // =====================================================
  // FORM BAHAN BAKU
  // =====================================================

  function resetIngredientForm() {
    setIngredientForm(emptyIngredientForm);
    setEditingId(null);
    setIngredientModalOpen(false);
  }

  function openCreateIngredient() {
    setIngredientForm(emptyIngredientForm);
    setEditingId(null);
    setIngredientModalOpen(true);

    setError("");
    setSuccess("");
  }

  function openEditIngredient(ingredient: Ingredient) {
    setEditingId(ingredient.id);
    setIngredientModalOpen(true);

    setIngredientForm({
      name: ingredient.name,
      unit: ingredient.unit,
      current_stock: String(ingredient.current_stock),
      minimum_stock: String(ingredient.minimum_stock),
    });

    setError("");
    setSuccess("");
  }

  async function saveIngredient(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault();

    setSaving(true);
    setError("");
    setSuccess("");

    try {
      const payload = {
        name: ingredientForm.name.trim(),
        unit: ingredientForm.unit.trim(),
        minimum_stock: Number(
          ingredientForm.minimum_stock
        ),

        ...(editingId === null
          ? {
              current_stock: Number(
                ingredientForm.current_stock
              ),
            }
          : {
              is_active:
                ingredients.find(
                  (item) => item.id === editingId
                )?.is_active ?? true,
            }),
      };

      await request(
        editingId === null
          ? "/inventory"
          : `/inventory/${editingId}`,
        {
          method: editingId === null ? "POST" : "PUT",
          body: JSON.stringify(payload),
        }
      );

      setSuccess(
        editingId === null
          ? "Bahan baku berhasil ditambahkan."
          : "Bahan baku berhasil diperbarui."
      );

      resetIngredientForm();

      await refreshData();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal menyimpan bahan baku."
      );
    } finally {
      setSaving(false);
    }
  }

  // =====================================================
  // NONAKTIFKAN BAHAN
  // =====================================================

  async function deactivateIngredient(
    ingredient: Ingredient
  ) {
    const confirmed = window.confirm(
      `Nonaktifkan bahan "${ingredient.name}"? Riwayat stok tetap disimpan.`
    );

    if (!confirmed) return;

    setError("");
    setSuccess("");

    try {
      await request(`/inventory/${ingredient.id}`, {
        method: "DELETE",
      });

      setSuccess("Bahan baku berhasil dinonaktifkan.");

      await refreshData();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Gagal menonaktifkan bahan."
      );
    }
  }

  // =====================================================
  // PERGERAKAN STOK
  // =====================================================

  function openMovement(ingredient: Ingredient) {
    setSelectedIngredient(ingredient);
    setMovementForm(emptyMovementForm);

    setError("");
    setMovementError("");
    setSuccess("");
  }

  async function saveMovement(
    event: FormEvent<HTMLFormElement>
  ) {
    event.preventDefault();

    if (!selectedIngredient) return;

    setSaving(true);
    setError("");
    setMovementError("");
    setSuccess("");

    try {
      await request(
        `/inventory/${selectedIngredient.id}/movements`,
        {
          method: "POST",
          body: JSON.stringify({
            type: movementForm.type,
            quantity: Number(movementForm.quantity),
            reason: movementForm.reason.trim(),
          }),
        }
      );

      setSuccess("Pergerakan stok berhasil dicatat.");

      setSelectedIngredient(null);
      setMovementForm(emptyMovementForm);

      setActiveTab("movements");
      setMovementPage(1);

      await refreshData();
    } catch (err) {
      setMovementError(
        err instanceof Error
          ? err.message
          : "Gagal mencatat pergerakan stok."
      );
    } finally {
      setSaving(false);
    }
  }

  // =====================================================
  // STATUS STOK
  // =====================================================

  function getStockStatus(ingredient: Ingredient) {
    const current = Number(ingredient.current_stock);
    const minimum = Number(ingredient.minimum_stock);

    if (current <= 0) {
      return {
        label: "Habis",
        className: "bg-red-100 text-red-700",
      };
    }

    if (current <= minimum) {
      return {
        label: "Stok Menipis",
        className: "bg-amber-100 text-amber-800",
      };
    }

    return {
      label: "Aman",
      className: "bg-emerald-100 text-emerald-700",
    };
  }

  // =====================================================
  // LOGOUT
  // =====================================================

  async function handleLogout() {
    try {
      await apiRequest("/logout", {
        method: "POST",
      });
    } finally {
      window.location.replace("/");
    }
  }

  // =====================================================
  // RENDER
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
                href === "/dashboard/inventory"
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
              Inventaris Bahan Baku
            </h2>
          </div>

          <button
            onClick={refreshData}
            disabled={loading || !isAuthenticated}
            className="rounded-xl bg-[#543923] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#69452d] disabled:opacity-50"
          >
            Refresh
          </button>
        </header>

        <div className="mx-auto max-w-[1450px] space-y-7 p-4 sm:p-7 lg:p-9">

          {/* JUDUL HALAMAN */}

          <section className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#9b795b]">
                Stock Control
              </p>

              <h1 className="mt-2 text-2xl font-black sm:text-3xl">
                Manajemen Inventaris
              </h1>

              <p className="mt-2 text-sm text-[#92775f]">
                Pantau bahan baku dan catat setiap perubahan stok.
              </p>
            </div>

            <button
              onClick={openCreateIngredient}
              className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white hover:bg-[#69452d]"
            >
              + Tambah Bahan
            </button>
          </section>

          {/* PESAN ERROR UMUM */}

          {error && (
            <div
              role="alert"
              className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700"
            >
              ⚠️ {error}
            </div>
          )}

          {/* PESAN SUKSES */}

          {success && (
            <div
              role="status"
              className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
            >
              {success}
            </div>
          )}

          {/* RINGKASAN INVENTARIS */}

          <section className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {[
              {
                label: "Bahan Aktif",
                value: summary.total,
                color: "text-[#543923]",
              },
              {
                label: "Stok Menipis",
                value: summary.low_stock,
                color: "text-amber-700",
              },
              {
                label: "Stok Habis",
                value: summary.out_of_stock,
                color: "text-red-700",
              },
              {
                label: "Bahan Nonaktif",
                value: summary.inactive,
                color: "text-[#92775f]",
              },
            ].map((item) => (
              <div
                key={item.label}
                className="rounded-2xl border border-[#eadccf] bg-[#fffaf4] p-5 shadow-sm"
              >
                <p className="text-xs font-medium text-[#92775f]">
                  {item.label}
                </p>

                <p
                  className={`mt-3 text-3xl font-black ${item.color}`}
                >
                  {item.value}
                </p>
              </div>
            ))}
          </section>

          {/* TAB INVENTARIS */}

          <section className="flex flex-wrap gap-2 border-b border-[#e4d3c2]">
            <button
              onClick={() => setActiveTab("ingredients")}
              className={`border-b-2 px-4 py-3 text-sm font-bold ${
                activeTab === "ingredients"
                  ? "border-[#69452d] text-[#543923]"
                  : "border-transparent text-[#92775f]"
              }`}
            >
              Data Bahan Baku
            </button>

            <button
              onClick={() => setActiveTab("movements")}
              className={`border-b-2 px-4 py-3 text-sm font-bold ${
                activeTab === "movements"
                  ? "border-[#69452d] text-[#543923]"
                  : "border-transparent text-[#92775f]"
              }`}
            >
              Riwayat Pergerakan Stok
            </button>
          </section>

          {/* TABEL BAHAN BAKU */}

          {activeTab === "ingredients" && (
            <section className="overflow-hidden rounded-2xl border border-[#eadccf] bg-[#fffaf4] shadow-sm">

              {/* FILTER */}

              <div className="flex flex-col gap-3 border-b border-[#eadccf] p-5 lg:flex-row">
                <input
                  value={search}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setIngredientPage(1);
                  }}
                  placeholder="Cari nama bahan baku..."
                  className="min-w-0 flex-1 rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                />

                <select
                  value={stockFilter}
                  onChange={(event) => {
                    setStockFilter(event.target.value);
                    setIngredientPage(1);
                  }}
                  className="rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                >
                  <option value="all">
                    Semua Kondisi Stok
                  </option>

                  <option value="low">
                    Stok Menipis
                  </option>

                  <option value="normal">
                    Stok Aman
                  </option>
                </select>

                <select
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value);
                    setIngredientPage(1);
                  }}
                  className="rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
                >
                  <option value="active">
                    Bahan Aktif
                  </option>

                  <option value="inactive">
                    Bahan Nonaktif
                  </option>

                  <option value="all">
                    Semua Status
                  </option>
                </select>
              </div>

              {/* DATA TABEL */}

              <div className="overflow-x-auto">
                <table className="w-full min-w-[850px] text-left text-sm">
                  <thead className="bg-[#f5eee6] text-xs uppercase tracking-wide text-[#80664e]">
                    <tr>
                      <th className="px-5 py-4">
                        Nama Bahan
                      </th>

                      <th className="px-5 py-4">
                        Stok Saat Ini
                      </th>

                      <th className="px-5 py-4">
                        Stok Minimum
                      </th>

                      <th className="px-5 py-4">
                        Kondisi
                      </th>

                      <th className="px-5 py-4">
                        Status
                      </th>

                      <th className="px-5 py-4 text-right">
                        Aksi
                      </th>
                    </tr>
                  </thead>

                  <tbody className="divide-y divide-[#eee2d7]">
                    {loading ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-5 py-12 text-center text-[#92775f]"
                        >
                          Memuat data inventaris...
                        </td>
                      </tr>
                    ) : ingredients.length === 0 ? (
                      <tr>
                        <td
                          colSpan={6}
                          className="px-5 py-12 text-center text-[#92775f]"
                        >
                          Belum ada bahan baku yang ditemukan.
                        </td>
                      </tr>
                    ) : (
                      ingredients.map((ingredient) => {
                        const stockStatus =
                          getStockStatus(ingredient);

                        return (
                          <tr
                            key={ingredient.id}
                            className="hover:bg-[#fdf8f2]"
                          >
                            <td className="px-5 py-4">
                              <p className="font-bold">
                                {ingredient.name}
                              </p>

                              <p className="mt-1 text-xs text-[#92775f]">
                                Satuan: {ingredient.unit}
                              </p>
                            </td>

                            <td className="px-5 py-4 font-bold">
                              {formatNumber(
                                ingredient.current_stock
                              )}{" "}
                              {ingredient.unit}
                            </td>

                            <td className="px-5 py-4 text-[#80664e]">
                              {formatNumber(
                                ingredient.minimum_stock
                              )}{" "}
                              {ingredient.unit}
                            </td>

                            <td className="px-5 py-4">
                              <span
                                className={`rounded-full px-3 py-1.5 text-xs font-bold ${stockStatus.className}`}
                              >
                                {stockStatus.label}
                              </span>
                            </td>

                            <td className="px-5 py-4">
                              {ingredient.is_active ? (
                                <span className="text-xs font-bold text-emerald-700">
                                  Aktif
                                </span>
                              ) : (
                                <span className="text-xs font-bold text-[#92775f]">
                                  Nonaktif
                                </span>
                              )}
                            </td>

                            <td className="px-5 py-4">
                              <div className="flex justify-end gap-2">
                                {ingredient.is_active && (
                                  <>
                                    <button
                                      onClick={() =>
                                        openMovement(ingredient)
                                      }
                                      className="rounded-lg bg-[#efe1d2] px-3 py-2 text-xs font-bold text-[#543923] hover:bg-[#e6d2bd]"
                                    >
                                      Atur Stok
                                    </button>

                                    <button
                                      onClick={() =>
                                        openEditIngredient(ingredient)
                                      }
                                      className="rounded-lg border border-[#ddc9b5] px-3 py-2 text-xs font-bold hover:bg-[#f5eee6]"
                                    >
                                      Edit
                                    </button>

                                    <button
                                      onClick={() =>
                                        deactivateIngredient(ingredient)
                                      }
                                      className="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-50"
                                    >
                                      Nonaktifkan
                                    </button>
                                  </>
                                )}
                              </div>
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>

              {/* PAGINATION BAHAN */}

              <div className="flex items-center justify-between border-t border-[#eadccf] px-5 py-4 text-xs text-[#92775f]">
                <span>
                  {ingredientPagination?.total ?? 0} bahan ditemukan
                </span>

                <div className="flex items-center gap-2">
                  <button
                    disabled={
                      !ingredientPagination ||
                      ingredientPage <= 1
                    }
                    onClick={() =>
                      setIngredientPage((page) => page - 1)
                    }
                    className="rounded-lg border border-[#ddc9b5] px-3 py-2 disabled:opacity-40"
                  >
                    Sebelumnya
                  </button>

                  <span>
                    {ingredientPagination?.current_page ?? 1} /{" "}
                    {ingredientPagination?.last_page ?? 1}
                  </span>

                  <button
                    disabled={
                      !ingredientPagination ||
                      ingredientPage >=
                        ingredientPagination.last_page
                    }
                    onClick={() =>
                      setIngredientPage((page) => page + 1)
                    }
                    className="rounded-lg border border-[#ddc9b5] px-3 py-2 disabled:opacity-40"
                  >
                    Berikutnya
                  </button>
                </div>
              </div>
            </section>
          )}

          {/* RIWAYAT PERGERAKAN STOK */}

          {activeTab === "movements" && (
            <section className="overflow-hidden rounded-2xl border border-[#eadccf] bg-[#fffaf4] shadow-sm">
              <div className="border-b border-[#eadccf] p-5">
                <h2 className="font-extrabold">
                  Riwayat Pergerakan Stok
                </h2>

                <p className="mt-1 text-xs text-[#92775f]">
                  Semua perubahan stok dan pengguna yang mencatatnya.
                </p>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] text-left text-sm">
                  <thead className="bg-[#f5eee6] text-xs uppercase tracking-wide text-[#80664e]">
                    <tr>
                      <th className="px-5 py-4">Waktu</th>
                      <th className="px-5 py-4">Bahan</th>
                      <th className="px-5 py-4">Jenis</th>
                      <th className="px-5 py-4">Jumlah</th>
                      <th className="px-5 py-4">Stok Sebelum</th>
                      <th className="px-5 py-4">Stok Sesudah</th>
                      <th className="px-5 py-4">Pengguna</th>
                      <th className="px-5 py-4">Alasan</th>
                    </tr>
                  </thead>

                  <tbody className="divide-y divide-[#eee2d7]">
                    {loading ? (
                      <tr>
                        <td
                          colSpan={8}
                          className="px-5 py-12 text-center"
                        >
                          Memuat riwayat...
                        </td>
                      </tr>
                    ) : movements.length === 0 ? (
                      <tr>
                        <td
                          colSpan={8}
                          className="px-5 py-12 text-center text-[#92775f]"
                        >
                          Belum ada riwayat pergerakan stok.
                        </td>
                      </tr>
                    ) : (
                      movements.map((movement) => (
                        <tr key={movement.id}>
                          <td className="whitespace-nowrap px-5 py-4 text-xs text-[#80664e]">
                            {formatDate(movement.movement_at)}
                          </td>

                          <td className="px-5 py-4 font-bold">
                            {movement.ingredient?.name || "-"}
                          </td>

                          <td className="px-5 py-4">
                            <span
                              className={`rounded-full px-3 py-1.5 text-xs font-bold ${
                                movement.type === "IN"
                                  ? "bg-emerald-100 text-emerald-700"
                                  : movement.type === "OUT"
                                    ? "bg-red-100 text-red-700"
                                    : "bg-amber-100 text-amber-800"
                              }`}
                            >
                              {movement.type === "IN"
                                ? "Stok Masuk"
                                : movement.type === "OUT"
                                  ? "Stok Keluar"
                                  : "Penyesuaian"}
                            </span>
                          </td>

                          <td className="px-5 py-4 font-bold">
                            {formatNumber(movement.quantity)}{" "}
                            {movement.ingredient?.unit}
                          </td>

                          <td className="px-5 py-4">
                            {formatNumber(movement.stock_before)}
                          </td>

                          <td className="px-5 py-4 font-bold text-[#543923]">
                            {formatNumber(movement.stock_after)}
                          </td>

                          <td className="px-5 py-4">
                            {movement.user?.name || "-"}
                          </td>

                          <td className="max-w-[220px] px-5 py-4 text-xs text-[#80664e]">
                            {movement.reason}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {/* PAGINATION RIWAYAT */}

              <div className="flex items-center justify-between border-t border-[#eadccf] px-5 py-4 text-xs text-[#92775f]">
                <span>
                  {movementPagination?.total ?? 0} riwayat
                </span>

                <div className="flex items-center gap-2">
                  <button
                    disabled={
                      !movementPagination ||
                      movementPage <= 1
                    }
                    onClick={() =>
                      setMovementPage((page) => page - 1)
                    }
                    className="rounded-lg border border-[#ddc9b5] px-3 py-2 disabled:opacity-40"
                  >
                    Sebelumnya
                  </button>

                  <span>
                    {movementPagination?.current_page ?? 1} /{" "}
                    {movementPagination?.last_page ?? 1}
                  </span>

                  <button
                    disabled={
                      !movementPagination ||
                      movementPage >= movementPagination.last_page
                    }
                    onClick={() =>
                      setMovementPage((page) => page + 1)
                    }
                    className="rounded-lg border border-[#ddc9b5] px-3 py-2 disabled:opacity-40"
                  >
                    Berikutnya
                  </button>
                </div>
              </div>
            </section>
          )}

          {/* FOOTER */}

          <footer className="border-t border-[#e4d3c2] pt-5 text-center text-xs text-[#a18b77]">
            LABOR_STATION Management System · Inventory
          </footer>
        </div>
      </div>

      {/* FORM TAMBAH / EDIT BAHAN */}

      {!selectedIngredient && (
        <div
          className="fixed bottom-5 right-5 z-30"
          aria-live="polite"
        >
          <div className="hidden">
            {editingId}
          </div>
        </div>
      )}

      {ingredientModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4">
          <form
            onSubmit={saveIngredient}
            className="w-full max-w-lg space-y-5 rounded-2xl bg-[#fffaf4] p-6 shadow-2xl"
          >
            <div>
              <h2 className="text-xl font-black">
                {editingId === null
                  ? "Tambah Bahan Baku"
                  : "Edit Bahan Baku"}
              </h2>

              <p className="mt-1 text-sm text-[#92775f]">
                Lengkapi informasi bahan baku.
              </p>
            </div>

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                Nama Bahan
              </span>

              <input
                required
                maxLength={150}
                value={ingredientForm.name}
                onChange={(event) =>
                  setIngredientForm({
                    ...ingredientForm,
                    name: event.target.value,
                  })
                }
                className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                placeholder="Contoh: Susu UHT"
              />
            </label>

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                Satuan
              </span>

              <input
                required
                maxLength={30}
                value={ingredientForm.unit}
                onChange={(event) =>
                  setIngredientForm({
                    ...ingredientForm,
                    unit: event.target.value,
                  })
                }
                className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                placeholder="Contoh: liter, kg, gram, pcs"
              />
            </label>

            {editingId === null && (
              <label className="block space-y-2">
                <span className="text-sm font-semibold">
                  Stok Awal
                </span>

                <input
                  required
                  type="number"
                  min="0"
                  step="0.001"
                  value={ingredientForm.current_stock}
                  onChange={(event) =>
                    setIngredientForm({
                      ...ingredientForm,
                      current_stock: event.target.value,
                    })
                  }
                  className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                />
              </label>
            )}

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                Batas Stok Minimum
              </span>

              <input
                required
                type="number"
                min="0"
                step="0.001"
                value={ingredientForm.minimum_stock}
                onChange={(event) =>
                  setIngredientForm({
                    ...ingredientForm,
                    minimum_stock: event.target.value,
                  })
                }
                className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
              />
            </label>

            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={resetIngredientForm}
                className="rounded-xl border border-[#ddc9b5] px-4 py-3 text-sm font-semibold"
              >
                Batal
              </button>

              <button
                type="submit"
                disabled={saving}
                className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white disabled:opacity-50"
              >
                {saving ? "Menyimpan..." : "Simpan Bahan"}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* FORM PERGERAKAN STOK */}

      {selectedIngredient && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4">
          <form
            onSubmit={saveMovement}
            className="w-full max-w-lg space-y-5 rounded-2xl bg-[#fffaf4] p-6 shadow-2xl"
          >
            <div>
              <p className="text-xs font-bold uppercase tracking-wider text-[#9b795b]">
                Stock Movement
              </p>

              <h2 className="mt-1 text-xl font-black">
                Atur Stok: {selectedIngredient.name}
              </h2>

              <p className="mt-2 text-sm text-[#92775f]">
                Stok saat ini:{" "}
                <strong>
                  {formatNumber(selectedIngredient.current_stock)}{" "}
                  {selectedIngredient.unit}
                </strong>
              </p>
            </div>

            {/* ERROR DITAMPILKAN DI DALAM MODAL */}

            {movementError && (
              <div
                role="alert"
                className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700"
              >
                ⚠️ {movementError}
              </div>
            )}

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                Jenis Pergerakan
              </span>

              <select
                value={movementForm.type}
                onChange={(event) =>
                  setMovementForm({
                    ...movementForm,
                    type: event.target.value as MovementForm["type"],
                    quantity: "",
                  })
                }
                className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm"
              >
                <option value="IN">Stok Masuk</option>
                <option value="OUT">Stok Keluar</option>
                <option value="ADJUSTMENT">
                  Penyesuaian Stok Akhir
                </option>
              </select>
            </label>

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                {movementForm.type === "ADJUSTMENT"
                  ? `Stok Akhir (${selectedIngredient.unit})`
                  : `Jumlah (${selectedIngredient.unit})`}
              </span>

              <input
                required
                type="number"
                min={
                  movementForm.type === "ADJUSTMENT"
                    ? "0"
                    : "0.001"
                }
                step="0.001"
                value={movementForm.quantity}
                onChange={(event) =>
                  setMovementForm({
                    ...movementForm,
                    quantity: event.target.value,
                  })
                }
                className="w-full rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                placeholder="Masukkan jumlah"
              />
            </label>

            {movementForm.type === "ADJUSTMENT" && (
              <p className="rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                Isi dengan jumlah stok akhir berdasarkan hasil hitung fisik,
                bukan jumlah selisih.
              </p>
            )}

            <label className="block space-y-2">
              <span className="text-sm font-semibold">
                Alasan / Keterangan
              </span>

              <textarea
                required
                maxLength={2000}
                rows={3}
                value={movementForm.reason}
                onChange={(event) =>
                  setMovementForm({
                    ...movementForm,
                    reason: event.target.value,
                  })
                }
                className="w-full resize-y rounded-xl border border-[#ddc9b5] bg-white px-4 py-3 text-sm outline-none focus:border-[#8b674d]"
                placeholder="Contoh: Pembelian bahan baku dari supplier..."
              />
            </label>

            <div className="flex justify-end gap-2 pt-2">
              <button
                type="button"
                onClick={() => {
                  setSelectedIngredient(null);
                  setMovementError("");
                  setMovementForm(emptyMovementForm);
                }}
                className="rounded-xl border border-[#ddc9b5] px-4 py-3 text-sm font-semibold"
              >
                Batal
              </button>

              <button
                type="submit"
                disabled={saving}
                className="rounded-xl bg-[#543923] px-5 py-3 text-sm font-bold text-white disabled:opacity-50"
              >
                {saving
                  ? "Menyimpan..."
                  : "Simpan Pergerakan"}
              </button>
            </div>
          </form>
        </div>
      )}
    </main>
  );
}