class MenuCart {
    constructor() { this.lines = []; this.nextId = 1; }
    save(line, editingId = null) {
        if (!Number.isInteger(line.quantity) || line.quantity < 1 || line.quantity > 50) throw new Error('Informe uma quantidade inteira entre 1 e 50.');
        const index = this.lines.findIndex(item => item.id === editingId);
        if (editingId !== null && index < 0) throw new Error('Este item não está mais na sacola. Adicione novamente.');
        const remaining = this.lines.filter(item => item.id !== editingId);
        if (remaining.length >= 50) throw new Error('A sacola aceita até 50 itens.');
        const total = remaining.filter(item => item.productId === line.productId).reduce((sum,item) => sum + item.quantity, line.quantity);
        if (total > 50) throw new Error('O limite é de 50 unidades do mesmo produto, somando suas combinações.');
        const saved = structuredClone({...line, id: editingId ?? this.nextId++});
        if (index >= 0) this.lines[index] = saved; else this.lines.push(saved);
        return saved.id;
    }
    remove(id) { this.lines = this.lines.filter(item => item.id !== id); }
    payload() {
        return this.lines.map(line => ({product_id:line.productId,quantity:line.quantity,
            ...(line.variantId ? {variant_id:line.variantId} : {}), choices:structuredClone(line.choices)}));
    }
}

