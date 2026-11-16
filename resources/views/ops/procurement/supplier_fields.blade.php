<div class="field"><label for="name">Supplier name *</label><input id="name" type="text" name="name" value="{{ old('name', $vendor->name ?? '') }}" required maxlength="150"></div>
<div class="form-row">
    <div class="field"><label for="contact_first_name">Contact first name</label><input id="contact_first_name" type="text" name="contact_first_name" value="{{ old('contact_first_name', $contact->first_name ?? '') }}" maxlength="100"></div>
    <div class="field"><label for="contact_last_name">Contact last name</label><input id="contact_last_name" type="text" name="contact_last_name" value="{{ old('contact_last_name', $contact->last_name ?? '') }}" maxlength="100"></div>
</div>
<div class="form-row">
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email', $contact->email ?? '') }}" maxlength="191"></div>
    <div class="field"><label for="phone">Phone</label><input id="phone" type="tel" name="phone" value="{{ old('phone', $vendor->phone ?? '') }}" maxlength="40"></div>
</div>
<div class="field"><label for="address">Address</label><input id="address" type="text" name="address" value="{{ old('address', $vendor->address1 ?? '') }}" maxlength="191"></div>
<div class="field"><label for="products_supplied">Products supplied</label><textarea id="products_supplied" name="products_supplied" maxlength="2000" placeholder="e.g. SSDs, RAM, laptop screens">{{ old('products_supplied', $vendor->private_notes ?? '') }}</textarea></div>
