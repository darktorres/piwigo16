export {};

const no_time_elapsed = pwg_getPageString('right now');
const unit_MB = pwg_getPageString('%s MB');

function displayResponse(domElem: any, values: any, mDivs: any,  mValues: any) {

    for (let index = 0; index < domElem.length; index++) {
        domElem[index].html(unit_MB.replace("%s",values[index]))
    }

    for (let index = 0; index < mDivs.length; index++) {
        const mDivName = mDivs[index].getAttribute("name");
        mDivs[index].title = unit_MB.replace("%s", mValues[mDivName])
    }

    $(".cache-lastCalculated-value").html(no_time_elapsed);
}

$(document).ready(function () {
    // eslint-disable-next-line @typescript-eslint/no-misused-promises -- returns a Promise jQuery's .on() never awaits either way, same as the original .js; fire-and-forget by design.
    $(".refresh-cache-size").on("click", function () {
        $(this).find(".refresh-icon").addClass("animate-spin");

        return new Promise<void>((res, rej) => {
            jQuery.ajax({
                url: "api/v1/cache-size",
                type: "GET",
                dataType: "json",
                success: function (data: any) {
                    res();

                    const domElemToRefresh = [$(".cache-size-value"), $(".multiple-pictures-sizes"), $(".multiple-compiledTemplate-sizes")];
                    const domElemValues: any[] = [data.cacheSize, data.msizes.all, data.templatesSize];
                    for (let i = 0; i < domElemValues.length; i++) {
                      domElemValues[i] = (domElemValues[i]/1024/1024).toFixed(2);
                    }

                    const multipleSizes = $(".delete-check-container").children(".delete-size-check");
                    const multipleSizesValues = data.msizes
                    for (const [key, _value] of Object.entries(multipleSizesValues)) {
                        multipleSizesValues[key] = (multipleSizesValues[key]/1024/1024).toFixed(2);
                    }

                    displayResponse(domElemToRefresh , domElemValues, multipleSizes,  multipleSizesValues);

                    $(".animate-spin").removeClass("animate-spin");
                },
                error: function(message: any) {
                    // eslint-disable-next-line @typescript-eslint/prefer-promise-reject-errors -- rejects with the real jqXHR error object, matching the original .js's own console.log(message) usage; not a new Error.
                    rej(message);
                    console.log(message);
                }
            });
        })
    })



})
