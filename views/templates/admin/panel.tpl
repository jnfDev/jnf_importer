{* 
    Prestashop's Admin comes with Bootstrap 3.1.1, 
    so we are going make use of it.
*}

<div class="panel">
    <div class="row">
        <h2 class="col-xs-12">{l s='To keep in mind' d='Modules.Jnfimporter.Panel'}</h2>
        <ul>
            <li>{l s='Your file must be an .csv file' d='Modules.Jnfimporter.Panel'}</li>
            <li>
                {l s='Your table should have the followings columns <b>Name, Reference, EAN13, Cost Price, Price, Tax, Quantity, Brand</b>, with the same order.' d='Modules.Jnfimporter.Panel'}
            </li>
            <li>{l s='If no tax rules is finded for a give tax rate, it will fallback to default value.' d='Modules.Jnfimporter.Panel'}</li>
            <li>{l s='If no category is found it for a give name, it will create a new category.' d='Modules.Jnfimporter.Panel'}</li>
            <li>{l s='If no brand is found it for a give name, it will create a new brand.' d='Modules.Jnfimporter.Panel'}</li>
            <li>{l s='After the import check the result <a href="%s">here</a>' sprintf=$admin_products_link d='Modules.Jnfimporter.Panel'}</li>
        </ul>
    </div>
</div>