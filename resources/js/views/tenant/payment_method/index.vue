<template>
  <div class="card">
    <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
      <h3 class="my-0">Listado de métodos de pago
        <el-tooltip class="item" effect="dark" content="Catálogo de Sunat - Operaciones relacionadas a la misma" placement="top-start">
            <i class="fa fa-info-circle"></i>
        </el-tooltip>
      </h3>
        <button
            type="button"
            class="btn btn-custom btn-sm"
            @click.prevent="clickCreate()"
          >
            <i class="fa fa-plus-circle"></i> Nuevo
          </button>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Código</th>
              <th width="50%" >Descripción</th>
              <th width="20%" class="text-center">Activo</th>
              <th class="text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in records">
              <td>{{ index + 1 }}</td>
              <td>{{ row.id }}</td>
              <td>{{ row.description }}</td>
              <td class="text-center">{{ row.active }}</td>
              <td class="text-right">
                <button
                  type="button"
                  class="btn waves-effect waves-light btn-xs btn-info"
                  @click.prevent="clickCreate(row.id)"
                >Editar</button>

                <template v-if="typeUser === 'admin'">
                  <button
                    type="button"
                    class="btn waves-effect waves-light btn-xs btn-danger"
                    @click.prevent="clickDelete(row.id)"
                  >Eliminar</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <payment-method-form :showDialog.sync="showDialog" :recordId="recordId"></payment-method-form>
  </div>
</template>

<script>
import PaymentMethodForm from "./form.vue";
import { deletable } from "../../../mixins/deletable";

export default {
  mixins: [deletable],
  props: ["typeUser"],
  components: { PaymentMethodForm },
  data() {
    return {
      showDialog: false,
      resource: "payment_method",
      recordId: null,
      records: []
    };
  },
  created() {
    this.$eventHub.$on("reloadData", () => {
      this.getData();
    });
    this.getData();
  },
  methods: {
    getData() {
      this.$http.get(`/${this.resource}/records`).then(response => {
        this.records = response.data.data;
      });
    },
    clickCreate(recordId = null) {
      this.recordId = recordId;
      this.showDialog = true;
    },
    clickDelete(id) {
      this.destroy(`/${this.resource}/${id}`).then(() =>
        this.$eventHub.$emit("reloadData")
      );
    }
  }
};
</script>
